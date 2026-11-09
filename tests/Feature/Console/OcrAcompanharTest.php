<?php

use App\Jobs\EnviarAvisoOcrJob;
use App\Jobs\VerificarReenvioOcrJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\ProcessoOcrAssinante;
use App\Models\ProcessoOcrAviso;
use App\Models\ProcessoOcrProcessamento;
use App\Services\Ocr\ContagemOcr;
use App\Services\Ocr\ContagemOcrService;
use App\Services\Ocr\ProcessamentoOcrService;
use App\Services\Ocr\WebhookOcrUrl;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Acompanhamento de reserva do OCR (T18; RF-13, RF-18 item 3 na rodada,
 * CT-05, LIM-02, LIM-03). Fila `sync` do phpunit.xml: o
 * VerificarReenvioOcrJob que a rodada despacha roda na hora, e o
 * EnviarDocumentoAoOcrJob que ele despacha também, como as filas fariam
 * entre duas rodadas. Só os avisos ficam na fila simulada, contados pelas
 * linhas de processo_ocr_avisos.
 *
 * O relógio anda com travelTo() a partir do minuto 0, a abertura do
 * processamento; cada rodada é um `ocr:acompanhar`, como o agendamento a
 * cada minuto faria.
 */

uses(DatabaseTransactions::class);

const BASE_WORKFLOW_ACOMPANHAR_OCR = 'http://n8n.test/webhook/juridico-ocr';
const TOKEN_WORKFLOW_ACOMPANHAR_OCR = 'token-workflow-acompanhar-8e1';
const LOGIN_ACOMPANHAR_OCR = 'login-acompanhar-3b7';
const SENHA_ACOMPANHAR_OCR = 'senha-acompanhar-6d2';
const POST_ACOMPANHAR_OCR = 'POST /documents/process';

beforeEach(function () {
    // O banco de dev pode ter processamentos reais em andamento (homologação):
    // a rodada só pode ver os deste teste.
    isolarProcessamentosOcrDoBancoDeDev();

    Queue::fake([EnviarAvisoOcrJob::class]);
    $this->freezeSecond();
    $this->minutoZero = now()->toImmutable();
    Storage::fake('s3');

    config()->set('services.n8n_ocr.url', BASE_WORKFLOW_ACOMPANHAR_OCR);
    config()->set('services.n8n_ocr.token', TOKEN_WORKFLOW_ACOMPANHAR_OCR);
    config()->set('services.n8n_ocr.base_conhecimento', 'base-processos');
    config()->set('services.n8n_ocr.webhook_base_url', 'http://host.docker.internal:8002');
    config()->set('services.n8n_ocr.prazo_sem_progresso_minutos', 25);
    config()->set('services.n8n_ocr.idade_maxima_sem_retorno_minutos', 20);
    config()->set('services.n8n_ocr.idade_maxima_absoluta_minutos', 60);
    definirCredenciaisPadrao(LOGIN_ACOMPANHAR_OCR, SENHA_ACOMPANHAR_OCR);

    // O acompanhamento e o reenvio vão direto do S3 (RF-09, R2-1): toda
    // chamada SOAP fica registrada.
    $this->mni = simularIntegracaoMni(fn () => throw new RuntimeException('o acompanhamento do OCR não chama o MNI'));
    $this->workflow = simularWorkflowAcompanharOcr();
});

/**
 * Processamento aberto no minuto 0, com o dono e 1 carona, sobre um processo
 * com `$quantidade` PDFs pendentes, todos já `baixado` com o objeto no S3.
 *
 * @return array{0: ProcessoOcrProcessamento, 1: Collection<int, ProcessoDocumento>, 2: Processo}
 */
function cenarioAcompanharOcr(int $quantidade = 2): array
{
    [$processo, $documentos] = criarDocumentosLote(array_fill(0, $quantidade, ['ocr_situacao' => null]));

    foreach ($documentos as $documento) {
        $path = "documentos-processos/{$processo->numero_processo}/{$documento->id_documento}.pdf";
        Storage::disk('s3')->put($path, "conteudo-{$documento->id_documento}");
        $documento->update(['status' => ProcessoDocumento::STATUS_BAIXADO, 'path' => $path]);
    }

    $processamento = ProcessoOcrProcessamento::create(['processo_id' => $processo->id]);

    foreach (['tok-dono', 'tok-carona'] as $i => $token) {
        ProcessoOcrAssinante::create([
            'processamento_id' => $processamento->id,
            'dono' => $i === 0,
            'callback_url' => "https://sistema.example/mni/p/{$processamento->id}/progresso?assinante={$token}",
            'callback_token' => $token,
        ]);
    }

    return [$processamento, $documentos->map(fn (ProcessoDocumento $documento) => $documento->fresh())->values(), $processo];
}

/**
 * Workflow simulado. `GET jobs/{id}` responde o que estiver em `->get[id]`
 * (`running` por padrão): um `status` do job, `'404'`, `'503'` ou
 * `'timeout'`. `DELETE` responde 200, e `POST` aceita com um job novo
 * por envio (`job-novo-1`, `job-novo-2`...).
 */
function simularWorkflowAcompanharOcr(): stdClass
{
    $workflow = new stdClass;
    $workflow->get = [];
    $workflow->novos = 0;

    Http::fake(function (Request $request) use ($workflow) {
        $caminho = Str::after($request->url(), BASE_WORKFLOW_ACOMPANHAR_OCR);

        if ($request->method() === 'GET') {
            $jobId = rawurldecode(Str::after($caminho, '/jobs/'));

            return match ($workflow->get[$jobId] ?? 'running') {
                '404' => Http::response(['detail' => 'Job não encontrado'], 404),
                '503' => Http::response('Service Unavailable', 503),
                'timeout' => Http::failedConnection('cURL error 28: Operation timed out')($request),
                default => Http::response(['id' => $jobId, 'status' => $workflow->get[$jobId] ?? 'running', 'error' => null], 200),
            };
        }

        if ($request->method() === 'DELETE') {
            return Http::response(['job_id' => Str::between($caminho, '/jobs/', '/chunks'), 'deleted_chunks' => 3], 200);
        }

        $workflow->novos++;

        return Http::response(['id' => "job-novo-{$workflow->novos}", 'status' => 'pending', 'error' => null], 202);
    });

    return $workflow;
}

/**
 * As chamadas ao workflow, na ordem: "MÉTODO caminho".
 *
 * @return list<string>
 */
function chamadasAcompanharOcr(): array
{
    return Http::recorded()
        ->map(fn (array $par) => $par[0]->method().' '.Str::after($par[0]->url(), BASE_WORKFLOW_ACOMPANHAR_OCR))
        ->values()
        ->all();
}

function irParaMinutoAcompanharOcr(int $minuto): void
{
    test()->travelTo(test()->minutoZero->addMinutes($minuto));
}

/** Uma rodada do `ocr:acompanhar` no minuto dado. */
function rodadaAcompanharOcr(int $minuto): int
{
    irParaMinutoAcompanharOcr($minuto);

    return Artisan::call('ocr:acompanhar');
}

/**
 * Uma rodada por minuto, de `$de` até `$ate`. `$antes($minuto)` roda no
 * mesmo minuto, antes da rodada (um envio ou um retorno do workflow).
 */
function rodadasAcompanharOcr(int $de, int $ate, ?Closure $antes = null): void
{
    foreach (range($de, $ate) as $minuto) {
        irParaMinutoAcompanharOcr($minuto);

        if ($antes !== null) {
            $antes($minuto);
        }

        expect(Artisan::call('ocr:acompanhar'))->toBe(0);
    }
}

/** O workflow aceitou o documento agora: ele passa a aguardar retorno (RF-11). */
function enviarAcompanharOcr(ProcessoDocumento $documento, string $jobId): void
{
    expect(app(ProcessamentoOcrService::class)->registrarEnvio($documento->fresh(), $jobId))->toBeTrue();
}

/** O retorno do workflow pelo webhook, como o n8n manda (RF-12). */
function retornoAcompanharOcr(ProcessoDocumento $documento, string $status = 'success'): void
{
    $documento = $documento->fresh();

    test()->postJson(WebhookOcrUrl::gerar($documento), ['status' => $status, 'job_id' => $documento->ocr_job_id], ['Accept' => '*/*'])
        ->assertOk();
}

function contagemAcompanharOcr(Processo $processo): ContagemOcr
{
    return app(ContagemOcrService::class)->contar($processo->id);
}

/**
 * @return Collection<int, ProcessoOcrAviso>
 */
function avisosAcompanharOcr(ProcessoOcrProcessamento $processamento, bool $final): Collection
{
    return ProcessoOcrAviso::query()
        ->whereIn('assinante_id', $processamento->assinantes()->pluck('id'))
        ->where('final', $final)
        ->get();
}

function quantasAcompanharOcr(string $chamada): int
{
    return count(array_filter(chamadasAcompanharOcr(), fn (string $feita) => $feita === $chamada));
}

// ---------- agendamento e comando ----------

it('agenda ocr:acompanhar a cada minuto, sem sobreposição e em segundo plano', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('ocr:acompanhar')
        ->assertSuccessful();

    /** @var Event|null $evento */
    $evento = collect(app(Schedule::class)->events())
        ->first(fn (Event $evento) => str_contains((string) $evento->command, 'ocr:acompanhar'));

    expect($evento)->not->toBeNull()
        ->and($evento->expression)->toBe('* * * * *')
        ->and($evento->withoutOverlapping)->toBeTrue()
        ->and($evento->runInBackground)->toBeTrue();

    Artisan::call('schedule:list');
    $linha = collect(explode("\n", Artisan::output()))->first(fn (string $linha) => str_contains($linha, 'ocr:acompanhar'));

    // O schedule:list alinha as colunas da expressão com espaços extras.
    expect(preg_replace('/\s+/', ' ', trim((string) $linha)))->toStartWith('* * * * * php artisan ocr:acompanhar');
});

it('o comando escreve o resumo da rodada e termina com sucesso', function () {
    [$processamento, $documentos] = cenarioAcompanharOcr(1);
    enviarAcompanharOcr($documentos[0], 'job-a');
    irParaMinutoAcompanharOcr(25);

    $this->artisan('ocr:acompanhar')
        ->expectsOutputToContain('1 processamento(s) em andamento; 1 com o prazo vencido (1 consulta(s), 1 encerrado(s))')
        ->assertSuccessful();
});

it('sem processamento em andamento, a rodada não chama o workflow nem o MNI', function () {
    [$processamento, $documentos] = cenarioAcompanharOcr(1);
    enviarAcompanharOcr($documentos[0], 'job-a');
    $processamento->update(['situacao' => ProcessoOcrProcessamento::SITUACAO_FALHOU, 'finalizado_em' => now()]);

    expect(rodadaAcompanharOcr(60))->toBe(0);

    Http::assertNothingSent();
    expect($this->mni->requisicoes)->toBe([])
        ->and($documentos[0]->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_ENVIADO);
});

// ---------- RF-13: prazo P sem progresso ----------

it('success na consulta depois de P: concluido, com processados = total (RF-13 AC a)', function () {
    [$processamento, $documentos, $processo] = cenarioAcompanharOcr(1);
    enviarAcompanharOcr($documentos[0], 'job-a');
    $this->workflow->get['job-a'] = 'success';

    rodadaAcompanharOcr(25);

    $processamento->refresh();
    $contagem = contagemAcompanharOcr($processo);
    $finais = avisosAcompanharOcr($processamento, true);

    expect(chamadasAcompanharOcr())->toBe(['GET /jobs/job-a'])
        ->and($processamento->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_CONCLUIDO)
        ->and($processamento->codigo_falha)->toBeNull()
        ->and($contagem->processados)->toBe($contagem->total)
        ->and($contagem->total)->toBe(1)
        ->and($finais)->toHaveCount(2)
        ->and($finais->every(fn (ProcessoOcrAviso $aviso) => $aviso->payload['situacao'] === 'concluido'
            && ! array_key_exists('codigo', $aviso->payload)))->toBeTrue()
        ->and($this->mni->requisicoes)->toBe([]);
});

it('running na consulta depois de P: falhou sem codigo, e o processo fica livre para um processamento novo (RF-13 AC b)', function () {
    [$processamento, $documentos, $processo] = cenarioAcompanharOcr(1);
    enviarAcompanharOcr($documentos[0], 'job-a');

    rodadaAcompanharOcr(25);

    $processamento->refresh();
    $finais = avisosAcompanharOcr($processamento, true);

    expect(chamadasAcompanharOcr())->toBe(['GET /jobs/job-a'])
        ->and($processamento->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_FALHOU)
        ->and($processamento->codigo_falha)->toBeNull()
        ->and($processamento->finalizado_em->equalTo(now()))->toBeTrue()
        // O documento segue aguardando retorno, para a abertura seguinte (RF-18).
        ->and($documentos[0]->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_ENVIADO)
        ->and($finais)->toHaveCount(2)
        ->and($finais->every(fn (ProcessoOcrAviso $aviso) => $aviso->payload['situacao'] === 'falhou'
            && ! array_key_exists('codigo', $aviso->payload)))->toBeTrue()
        ->and(app(ProcessamentoOcrService::class)->processamentoAberto($processo->id))->toBeNull();

    // Sem processamento em andamento, o pedido seguinte abre outro, e não
    // entra como carona: o índice único parcial aceita um novo aberto.
    $novo = ProcessoOcrProcessamento::create(['processo_id' => $processo->id]);

    expect($novo->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO)
        ->and($novo->id)->not->toBe($processamento->id);
});

it('com o último progresso há 24 min, 0 GET e em_andamento; aos 25 min, a consulta e o aviso final (RF-13 AC c)', function () {
    [$processamento, $documentos] = cenarioAcompanharOcr(1);

    // Enviado aos 5 min: a idade dele não chega a A antes de P.
    rodadasAcompanharOcr(1, 24, function (int $minuto) use ($documentos) {
        if ($minuto === 5) {
            enviarAcompanharOcr($documentos[0], 'job-a');
        }
    });

    Http::assertNothingSent();
    expect($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO)
        ->and(avisosAcompanharOcr($processamento, true))->toHaveCount(0);

    rodadaAcompanharOcr(25);

    expect(chamadasAcompanharOcr())->toBe(['GET /jobs/job-a'])
        ->and($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_FALHOU)
        ->and(avisosAcompanharOcr($processamento, true))->toHaveCount(2);
});

it('um progresso aos 10 min empurra o fim para os 35 min depois da abertura (RF-13 AC c)', function () {
    [$processamento, $documentos] = cenarioAcompanharOcr(3);

    rodadasAcompanharOcr(1, 34, function (int $minuto) use ($documentos) {
        match ($minuto) {
            9 => enviarAcompanharOcr($documentos[0], 'job-1'),
            10 => retornoAcompanharOcr($documentos[0]),
            15 => enviarAcompanharOcr($documentos[1], 'job-2'),
            default => null,
        };
    });

    $processamento->refresh();

    expect($processamento->ultimo_progresso_em->equalTo($this->minutoZero->addMinutes(10)))->toBeTrue()
        ->and($processamento->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO);
    Http::assertNothingSent();

    rodadaAcompanharOcr(35);
    $processamento->refresh();

    expect(chamadasAcompanharOcr())->toBe(['GET /jobs/job-2'])
        ->and($processamento->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_FALHOU)
        ->and($processamento->finalizado_em->equalTo($this->minutoZero->addMinutes(35)))->toBeTrue();
});

it('na rodada de P, consulta cada documento aguardando retorno, inclusive de processamento anterior, e aplica o resultado pelas regras de RF-12 (RF-13 item 1)', function (string $resposta, string $situacaoEsperada) {
    [$processamento, $documentos, $processo] = cenarioAcompanharOcr(3);

    // E foi enviado num processamento anterior, que terminou sem o retorno dele.
    ProcessoOcrProcessamento::create([
        'processo_id' => $processo->id,
        'situacao' => ProcessoOcrProcessamento::SITUACAO_FALHOU,
        'aberto_em' => now()->subMinutes(130),
        'finalizado_em' => now()->subMinutes(100),
    ]);
    $documentos[0]->update([
        'ocr_situacao' => ProcessoDocumento::OCR_ENVIADO,
        'ocr_job_id' => 'job-e',
        'ocr_enviado_em' => now()->subMinutes(120),
    ]);
    irParaMinutoAcompanharOcr(5);
    enviarAcompanharOcr($documentos[1], 'job-f');
    enviarAcompanharOcr($documentos[2], 'job-g');

    $this->workflow->get = ['job-e' => $resposta, 'job-f' => 'running', 'job-g' => '503'];

    rodadaAcompanharOcr(25);

    $processamento->refresh();
    $antigo = $documentos[0]->fresh();

    // Só consultas: a rodada de P não cancela nem reenvia (R4-1).
    expect(chamadasAcompanharOcr())->toBe(['GET /jobs/job-e', 'GET /jobs/job-f', 'GET /jobs/job-g'])
        ->and($antigo->ocr_situacao)->toBe($situacaoEsperada)
        ->and($antigo->ocr_job_id)->toBe('job-e')
        ->and($documentos[1]->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_ENVIADO)
        ->and($documentos[2]->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_ENVIADO)
        // Sobram documentos aguardando retorno: falhou, sem codigo (RF-13 item 2).
        ->and($processamento->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_FALHOU)
        ->and($processamento->codigo_falha)->toBeNull()
        ->and(avisosAcompanharOcr($processamento, true))->toHaveCount(2)
        ->and($this->mni->requisicoes)->toBe([]);

    $mudou = $situacaoEsperada !== ProcessoDocumento::OCR_ENVIADO;

    // A mudança é progresso do processamento aberto: 1 aviso por assinante antes do final.
    expect(avisosAcompanharOcr($processamento, false))->toHaveCount($mudou ? 2 : 0);

    if ($situacaoEsperada === ProcessoDocumento::OCR_ERRO) {
        expect($antigo->ocr_erro)->toContain('fim do prazo')
            ->and(contagemAcompanharOcr($processo)->comErro)->toBe(1);
    }
})->with([
    'success → processado' => ['success', ProcessoDocumento::OCR_PROCESSADO],
    'failed → com_erro' => ['failed', ProcessoDocumento::OCR_ERRO],
    'cancelled → com_erro' => ['cancelled', ProcessoDocumento::OCR_ERRO],
    '404 → com_erro' => ['404', ProcessoDocumento::OCR_ERRO],
    'pending → segue aguardando' => ['pending', ProcessoDocumento::OCR_ENVIADO],
    'running → segue aguardando' => ['running', ProcessoDocumento::OCR_ENVIADO],
    '503 → segue aguardando' => ['503', ProcessoDocumento::OCR_ENVIADO],
    'tempo esgotado → segue aguardando' => ['timeout', ProcessoDocumento::OCR_ENVIADO],
]);

it('na rodada de P, só os documentos aguardando retorno com job são consultados; o processado fica como está', function () {
    [$processamento, $documentos] = cenarioAcompanharOcr(3);
    enviarAcompanharOcr($documentos[0], 'job-a');
    $documentos[1]->update(['ocr_situacao' => ProcessoDocumento::OCR_PROCESSADO, 'ocr_job_id' => 'job-b']);
    // Aguardando retorno sem job: não há o que consultar, e ele ainda conta como aguardando.
    $documentos[2]->update(['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => null, 'ocr_enviado_em' => now()]);
    $this->workflow->get = ['job-a' => 'success', 'job-b' => 'failed'];

    rodadaAcompanharOcr(25);

    expect(chamadasAcompanharOcr())->toBe(['GET /jobs/job-a'])
        ->and($documentos[0]->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_PROCESSADO)
        ->and($documentos[1]->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_PROCESSADO)
        ->and($documentos[2]->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_ENVIADO)
        ->and($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_FALHOU);
});

it('na rodada de P, o retorno do último documento conclui o processamento, sem falhou', function () {
    [$processamento, $documentos] = cenarioAcompanharOcr(2);
    enviarAcompanharOcr($documentos[0], 'job-a');
    $documentos[1]->update(['ocr_situacao' => ProcessoDocumento::OCR_PROCESSADO, 'ocr_job_id' => 'job-b']);
    $this->workflow->get = ['job-a' => 'failed'];

    rodadaAcompanharOcr(25);

    $processamento->refresh();

    // `com_erro` > 0 sem pendente nem aguardando: concluido (RF-15).
    expect(chamadasAcompanharOcr())->toBe(['GET /jobs/job-a'])
        ->and($documentos[0]->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_ERRO)
        ->and($processamento->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_CONCLUIDO)
        ->and(avisosAcompanharOcr($processamento, true))->toHaveCount(2)
        ->and(avisosAcompanharOcr($processamento, true)->every(fn (ProcessoOcrAviso $aviso) => $aviso->payload['situacao'] === 'concluido'
            && $aviso->payload['com_erro'] === 1))->toBeTrue();
});

it('P vencido só com documentos pendentes: 0 GET e falhou', function () {
    [$processamento] = cenarioAcompanharOcr(2);

    rodadaAcompanharOcr(25);

    Http::assertNothingSent();
    expect($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_FALHOU)
        ->and($processamento->fresh()->codigo_falha)->toBeNull();
});

it('P e A vêm da configuração', function () {
    config()->set('services.n8n_ocr.prazo_sem_progresso_minutos', 12);
    config()->set('services.n8n_ocr.idade_maxima_sem_retorno_minutos', 8);
    [$processamento, $documentos] = cenarioAcompanharOcr(2);
    enviarAcompanharOcr($documentos[0], 'job-a');

    rodadasAcompanharOcr(1, 7);
    Http::assertNothingSent();

    rodadaAcompanharOcr(8);
    expect(chamadasAcompanharOcr())->toBe(['GET /jobs/job-a']);

    rodadaAcompanharOcr(11);
    expect($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO);

    rodadaAcompanharOcr(12);
    expect($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_FALHOU);
});

// ---------- RF-18 item 3 na rodada: consulta antes do reenvio ----------

it('a rodada só despacha a consulta antes do reenvio, na fila ocr-envio, e não devolve nada à fila (D9)', function () {
    Queue::fake([VerificarReenvioOcrJob::class, EnviarAvisoOcrJob::class]);
    [$processamento, $documentos] = cenarioAcompanharOcr(3);
    enviarAcompanharOcr($documentos[0], 'job-a');
    irParaMinutoAcompanharOcr(5);
    enviarAcompanharOcr($documentos[1], 'job-b');

    rodadaAcompanharOcr(20);

    // Só o documento com idade ≥ A; o de idade 15 fica.
    Queue::assertPushedTimes(VerificarReenvioOcrJob::class, 1);
    Queue::assertPushedOn('ocr-envio', VerificarReenvioOcrJob::class, fn (VerificarReenvioOcrJob $job) => $job->processamentoId === $processamento->id
        && $job->documentoId === $documentos[0]->id);
    Http::assertNothingSent();

    $documento = $documentos[0]->fresh();

    expect($documento->ocr_situacao)->toBe(ProcessoDocumento::OCR_ENVIADO)
        ->and($documento->ocr_job_id)->toBe('job-a')
        ->and($documento->ocr_job_id_cancelar)->toBeNull();
});

it('cada processamento tem a sua rodada: o vencido termina, e o outro despacha a consulta antes do reenvio', function () {
    Queue::fake([VerificarReenvioOcrJob::class, EnviarAvisoOcrJob::class]);
    [$vencido, $documentosVencido] = cenarioAcompanharOcr(1);
    enviarAcompanharOcr($documentosVencido[0], 'job-vencido');

    irParaMinutoAcompanharOcr(10);
    [$aberto, $documentosAberto] = cenarioAcompanharOcr(2);
    enviarAcompanharOcr($documentosAberto[0], 'job-aberto');

    rodadaAcompanharOcr(30);

    expect($vencido->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_FALHOU)
        ->and($aberto->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO)
        ->and(chamadasAcompanharOcr())->toBe(['GET /jobs/job-vencido']);

    Queue::assertPushedTimes(VerificarReenvioOcrJob::class, 1);
    Queue::assertPushed(VerificarReenvioOcrJob::class, fn (VerificarReenvioOcrJob $job) => $job->processamentoId === $aberto->id
        && $job->documentoId === $documentosAberto[0]->id);
});

it('com progresso a cada 10 min e o job antigo em failed: na rodada em que a idade chega a A, 1 GET, 1 DELETE e 1 POST, 0 SOAP, e o processamento segue (RF-18 AC f)', function () {
    [$processamento, $documentos] = cenarioAcompanharOcr(3);
    [$documento, $outro1, $outro2] = $documentos;
    enviarAcompanharOcr($documento, 'job-antigo');
    $this->workflow->get['job-antigo'] = 'failed';

    rodadasAcompanharOcr(1, 20, function (int $minuto) use ($outro1, $outro2) {
        match ($minuto) {
            9 => enviarAcompanharOcr($outro1, 'job-o1'),
            10 => retornoAcompanharOcr($outro1),
            19 => enviarAcompanharOcr($outro2, 'job-o2'),
            20 => retornoAcompanharOcr($outro2),
            default => null,
        };
    });

    expect(chamadasAcompanharOcr())->toBe(['GET /jobs/job-antigo', 'DELETE /jobs/job-antigo/chunks', POST_ACOMPANHAR_OCR])
        ->and($this->mni->requisicoes)->toBe([])
        ->and($documento->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_ENVIADO)
        ->and($documento->fresh()->ocr_job_id)->toBe('job-novo-1')
        ->and($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['filename'] === "{$documento->id_documento}.pdf");

    // Reenviado agora, o documento tem idade 0: a rodada seguinte não consulta nada.
    rodadaAcompanharOcr(21);

    expect(chamadasAcompanharOcr())->toHaveCount(3);
});

it('sem progresso e com 404: reenvia na rodada dos 20 min e, na dos 25, consulta só o job novo e termina falhou (RF-18 AC h, k)', function () {
    [$processamento, $documentos] = cenarioAcompanharOcr(1);
    enviarAcompanharOcr($documentos[0], 'job-antigo');
    $this->workflow->get['job-antigo'] = '404';

    rodadasAcompanharOcr(1, 19);
    Http::assertNothingSent();

    rodadaAcompanharOcr(20);
    expect(chamadasAcompanharOcr())->toBe(['GET /jobs/job-antigo', 'DELETE /jobs/job-antigo/chunks', POST_ACOMPANHAR_OCR]);

    // O reenvio não é progresso (R4-1): não adia o fim (AC k).
    rodadasAcompanharOcr(21, 24);
    expect(chamadasAcompanharOcr())->toHaveCount(3)
        ->and($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO)
        ->and($processamento->fresh()->ultimo_progresso_em)->toBeNull();

    rodadaAcompanharOcr(25);

    expect(chamadasAcompanharOcr())->toBe([
        'GET /jobs/job-antigo', 'DELETE /jobs/job-antigo/chunks', POST_ACOMPANHAR_OCR,
        'GET /jobs/job-novo-1',
    ])
        ->and($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_FALHOU)
        ->and($processamento->fresh()->finalizado_em->equalTo($this->minutoZero->addMinutes(25)))->toBeTrue()
        ->and($this->mni->requisicoes)->toBe([]);
});

it('enviado aos 5 min: na rodada dos 25, P vence primeiro, a consulta responde running, falhou e 0 DELETE e 0 POST (RF-18 AC j, LIM-02)', function () {
    [$processamento, $documentos] = cenarioAcompanharOcr(1);

    rodadasAcompanharOcr(1, 25, function (int $minuto) use ($documentos) {
        if ($minuto === 5) {
            enviarAcompanharOcr($documentos[0], 'job-a');
        }
    });

    expect(chamadasAcompanharOcr())->toBe(['GET /jobs/job-a'])
        ->and($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_FALHOU)
        ->and($documentos[0]->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_ENVIADO)
        ->and($documentos[0]->fresh()->ocr_job_id)->toBe('job-a');
});

it('404, failed ou cancelled com idade ≥ A: 1 GET, 1 DELETE e 1 POST na rodada (RF-18 AC p, R7-1)', function (string $status) {
    [$processamento, $documentos] = cenarioAcompanharOcr(2);
    enviarAcompanharOcr($documentos[0], 'job-antigo');
    $this->workflow->get['job-antigo'] = $status;

    rodadaAcompanharOcr(20);

    expect(chamadasAcompanharOcr())->toBe(['GET /jobs/job-antigo', 'DELETE /jobs/job-antigo/chunks', POST_ACOMPANHAR_OCR])
        ->and($documentos[0]->fresh()->ocr_job_id)->toBe('job-novo-1')
        ->and($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO)
        ->and($this->mni->requisicoes)->toBe([]);
})->with(['404', 'failed', 'cancelled']);

it('running ou pending: 0 DELETE e 0 POST, 1 novo GET na rodada seguinte e, com 404, 1 DELETE e 1 POST (RF-18 AC l)', function (string $status) {
    [$processamento, $documentos] = cenarioAcompanharOcr(2);
    enviarAcompanharOcr($documentos[0], 'job-antigo');
    $this->workflow->get['job-antigo'] = $status;

    rodadaAcompanharOcr(20);

    expect(chamadasAcompanharOcr())->toBe(['GET /jobs/job-antigo'])
        ->and($documentos[0]->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_ENVIADO)
        ->and($documentos[0]->fresh()->ocr_job_id)->toBe('job-antigo');

    rodadaAcompanharOcr(21);

    expect(chamadasAcompanharOcr())->toBe(['GET /jobs/job-antigo', 'GET /jobs/job-antigo']);

    $this->workflow->get['job-antigo'] = '404';
    rodadaAcompanharOcr(22);

    expect(chamadasAcompanharOcr())->toBe([
        'GET /jobs/job-antigo', 'GET /jobs/job-antigo',
        'GET /jobs/job-antigo', 'DELETE /jobs/job-antigo/chunks', POST_ACOMPANHAR_OCR,
    ])
        ->and($documentos[0]->fresh()->ocr_job_id)->toBe('job-novo-1');
})->with(['running', 'pending']);

it('success com idade ≥ A: 0 DELETE e 0 POST, processados + 1, 1 aviso por assinante e P recomeça (RF-18 AC m)', function () {
    [$processamento, $documentos, $processo] = cenarioAcompanharOcr(2);
    enviarAcompanharOcr($documentos[0], 'job-antigo');
    $this->workflow->get['job-antigo'] = 'success';
    $antes = contagemAcompanharOcr($processo);

    rodadaAcompanharOcr(20);

    $processamento->refresh();
    $avisos = avisosAcompanharOcr($processamento, false);

    expect(chamadasAcompanharOcr())->toBe(['GET /jobs/job-antigo'])
        ->and(contagemAcompanharOcr($processo)->processados)->toBe($antes->processados + 1)
        ->and($avisos)->toHaveCount(2)
        ->and($avisos->pluck('assinante_id')->unique())->toHaveCount(2)
        ->and($avisos->every(fn (ProcessoOcrAviso $aviso) => $aviso->payload['situacao'] === 'em_andamento'))->toBeTrue()
        ->and($processamento->ultimo_progresso_em->equalTo($this->minutoZero->addMinutes(20)))->toBeTrue();

    // P conta de novo do minuto 20: aos 25 e aos 44 segue aberto; aos 45, termina.
    rodadaAcompanharOcr(25);
    expect($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO);

    rodadaAcompanharOcr(44);
    expect($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO);

    rodadaAcompanharOcr(45);
    expect($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_FALHOU)
        ->and(chamadasAcompanharOcr())->toBe(['GET /jobs/job-antigo']);
});

it('503 ou tempo esgotado na consulta: 0 DELETE e 0 POST na rodada, e 1 novo GET na seguinte (RF-18 AC n, R6-2)', function (string $falha) {
    [$processamento, $documentos] = cenarioAcompanharOcr(2);
    enviarAcompanharOcr($documentos[0], 'job-antigo');
    $this->workflow->get['job-antigo'] = $falha;

    expect(rodadaAcompanharOcr(20))->toBe(0)
        ->and(chamadasAcompanharOcr())->toBe(['GET /jobs/job-antigo'])
        ->and($documentos[0]->fresh()->ocr_job_id)->toBe('job-antigo')
        ->and($documentos[0]->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_ENVIADO);

    expect(rodadaAcompanharOcr(21))->toBe(0)
        ->and(chamadasAcompanharOcr())->toBe(['GET /jobs/job-antigo', 'GET /jobs/job-antigo'])
        ->and($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO);
})->with(['503', 'timeout']);

it('running sempre, com progresso de outros documentos: 0 reenvios até M e, na primeira rodada com idade ≥ M, 1 DELETE e 1 POST (RF-18 AC o, LIM-03)', function () {
    [$processamento, $documentos] = cenarioAcompanharOcr(7);
    $documento = $documentos[0];
    $outros = $documentos->slice(1)->values();
    enviarAcompanharOcr($documento, 'job-antigo');

    // Um retorno de outro documento a cada 10 min mantém o processamento aberto.
    rodadasAcompanharOcr(1, 59, function (int $minuto) use ($outros) {
        if ($minuto % 10 === 9) {
            enviarAcompanharOcr($outros[intdiv($minuto, 10)], "job-outro-{$minuto}");
        }

        if ($minuto % 10 === 0) {
            retornoAcompanharOcr($outros[intdiv($minuto, 10) - 1]);
        }
    });

    // Uma consulta por rodada, dos 20 aos 59 min, sem cancelar nem reenviar.
    expect(quantasAcompanharOcr('GET /jobs/job-antigo'))->toBe(40)
        ->and(chamadasAcompanharOcr())->toHaveCount(40)
        ->and($documento->fresh()->ocr_job_id)->toBe('job-antigo');

    rodadaAcompanharOcr(60);

    expect(quantasAcompanharOcr('GET /jobs/job-antigo'))->toBe(41)
        ->and(array_slice(chamadasAcompanharOcr(), -3))->toBe(['GET /jobs/job-antigo', 'DELETE /jobs/job-antigo/chunks', POST_ACOMPANHAR_OCR])
        ->and(quantasAcompanharOcr('DELETE /jobs/job-antigo/chunks'))->toBe(1)
        ->and(quantasAcompanharOcr(POST_ACOMPANHAR_OCR))->toBe(1)
        ->and($documento->fresh()->ocr_job_id)->toBe('job-novo-1')
        ->and($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO)
        ->and($this->mni->requisicoes)->toBe([]);
});
