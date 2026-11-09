<?php

use App\Jobs\BaixarDocumentoParaOcrJob;
use App\Jobs\EnviarAvisoOcrJob;
use App\Jobs\EnviarDocumentoAoOcrJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\ProcessoOcrAssinante;
use App\Models\ProcessoOcrAviso;
use App\Models\ProcessoOcrProcessamento;
use App\Services\Ocr\ContagemOcr;
use App\Services\Ocr\ContagemOcrService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Download para o OCR (T17; RF-09, RF-10, RF-02 AC c, RNF-01). O MNI é o
 * IntegracaoBase simulado, o S3 é o Storage::fake e o workflow é o
 * Http::fake. Os casos de tentativas rodam pela fila `database` com o
 * worker real (`queue:work --once`), para que `$tries` e `backoff()` valham
 * como em produção; os demais rodam pela fila `sync` do phpunit.xml.
 */

uses(DatabaseTransactions::class);

const LOGIN_DONO_DOWNLOAD_OCR = 'login-dono-download-5a1';
const SENHA_DONO_DOWNLOAD_OCR = 'senha-dono-download-7c3';
const LOGIN_PADRAO_DOWNLOAD_OCR = 'login-padrao-download-2e8';
const SENHA_PADRAO_DOWNLOAD_OCR = 'senha-padrao-download-4b6';
const LOGIN_TRIBUNAL_DOWNLOAD_OCR = 'login-tribunal-download-9d2';
const SENHA_TRIBUNAL_DOWNLOAD_OCR = 'senha-tribunal-download-1f4';
const BASE_WORKFLOW_DOWNLOAD_OCR = 'http://n8n.test/webhook/juridico-ocr';
const TOKEN_WORKFLOW_DOWNLOAD_OCR = 'token-workflow-download-6c9';
const TEXTO_RECUSA_DOWNLOAD_OCR = 'Erro ao realizar login via MNI. exception invoking: loginFailed';

beforeEach(function () {
    isolarAvisosOcrDoBancoDeDev();
    $this->freezeSecond();
    Storage::fake('s3');
    criarTokenApi();

    config()->set('services.n8n_ocr.url', BASE_WORKFLOW_DOWNLOAD_OCR);
    config()->set('services.n8n_ocr.token', TOKEN_WORKFLOW_DOWNLOAD_OCR);
    config()->set('services.n8n_ocr.base_conhecimento', 'base-processos');
    config()->set('services.n8n_ocr.webhook_base_url', 'http://host.docker.internal:8002');
    config()->set('services.n8n_ocr.prazo_sem_progresso_minutos', 25);
    config()->set('services.n8n_ocr.idade_maxima_sem_retorno_minutos', 20);
    config()->set('services.n8n_ocr.idade_maxima_absoluta_minutos', 60);

    // O par padrão existe no ambiente: o download do OCR nunca o usa (RF-02).
    definirCredenciaisPadrao(LOGIN_PADRAO_DOWNLOAD_OCR, SENHA_PADRAO_DOWNLOAD_OCR);

    $this->mni = simularIntegracaoMni(respostaMniDownloadOcr());
});

/**
 * Resposta do MNI simulado à consulta de 1 documento: o documento pedido,
 * salvo os ids em `$falhas`, que recebem o que a closure de lá devolve ou
 * lança.
 *
 * @param  array<int, Closure>  $falhas  id_documento → responder
 */
function respostaMniDownloadOcr(array $falhas = []): Closure
{
    return function (string $operacao, array $parametros) use ($falhas) {
        $id = (int) $parametros['documento'];

        if (array_key_exists($id, $falhas)) {
            return $falhas[$id]();
        }

        $documento = ProcessoDocumento::query()->where('id_documento', $id)->firstOrFail();

        return (object) ['sucesso' => true, 'processo' => (object) ['documento' => documentoMniFake($documento)]];
    };
}

/**
 * Processamento em andamento, com o dono e `$caronas` caronas, sobre um
 * processo com os documentos dados (atributos de criarDocumentosLote). Os
 * casos com o worker real vão sem assinantes: o Queue::fake não deixa o
 * worker ler a fila `database`, e sem assinante não há aviso a enviar.
 *
 * @return array{0: ProcessoOcrProcessamento, 1: \Illuminate\Support\Collection<int, ProcessoDocumento>, 2: Processo}
 */
function cenarioDownloadOcr(array $documentos = [[]], int $caronas = 1, bool $comAssinantes = true): array
{
    [$processo, $criados] = criarDocumentosLote(
        array_map(fn (array $atributos) => array_merge(['ocr_situacao' => null], $atributos), $documentos),
        tribunal: ['login' => LOGIN_TRIBUNAL_DOWNLOAD_OCR, 'password' => Crypt::encrypt(SENHA_TRIBUNAL_DOWNLOAD_OCR)],
    );

    $processamento = ProcessoOcrProcessamento::create(['processo_id' => $processo->id]);

    foreach ($comAssinantes ? range(0, $caronas) : [] as $i) {
        ProcessoOcrAssinante::create([
            'processamento_id' => $processamento->id,
            'dono' => $i === 0,
            'callback_url' => "https://sistema.example/mni/p/{$processamento->id}/progresso?assinante={$i}",
            'callback_token' => "tok-assinante-{$i}",
        ]);
    }

    return [$processamento, $criados, $processo];
}

/** Grava o objeto do documento no S3, como o download de hoje gravaria. */
function gravarNoS3DownloadOcr(ProcessoDocumento $documento, string $extensao, array $atributos = []): ProcessoDocumento
{
    $path = "documentos-processos/{$documento->processo->numero_processo}/{$documento->id_documento}.{$extensao}";
    Storage::disk('s3')->put($path, "conteudo-{$documento->id_documento}");
    $documento->update(array_merge(['status' => ProcessoDocumento::STATUS_BAIXADO, 'path' => $path], $atributos));

    return $documento->fresh();
}

function baixarParaOcr(ProcessoOcrProcessamento $processamento, ProcessoDocumento $documento): void
{
    BaixarDocumentoParaOcrJob::dispatch($processamento->id, $documento->id, LOGIN_DONO_DOWNLOAD_OCR, SENHA_DONO_DOWNLOAD_OCR);
}

function contagemDownloadOcr(Processo $processo): ContagemOcr
{
    return app(ContagemOcrService::class)->contar($processo->id);
}

/** Workflow simulado: aceita todo envio com um job novo por documento. */
function simularWorkflowDownloadOcr(): void
{
    Http::fake(function (Request $request) {
        return $request->method() === 'POST'
            ? Http::response(['id' => (string) Str::uuid(), 'status' => 'pending', 'error' => null], 202)
            : Http::response(['detail' => 'não esperado'], 599);
    });
}

/** Toda chamada SOAP leva o par do dono, nunca o padrão nem o do tribunal (RF-02 AC c). */
function expectSoapComOParDoDonoDownloadOcr(stdClass $mni): void
{
    foreach ($mni->requisicoes as $requisicao) {
        expect($requisicao['parametros']['idConsultante'])->toBe(LOGIN_DONO_DOWNLOAD_OCR)
            ->and($requisicao['parametros']['senhaConsultante'])->toBe(SENHA_DONO_DOWNLOAD_OCR);
    }
}

/** Despacha na fila `database`, como a fila do Horizon guardaria. */
function despacharNaFilaDownloadOcr(ProcessoOcrProcessamento $processamento, ProcessoDocumento $documento): object
{
    dispatch((new BaixarDocumentoParaOcrJob($processamento->id, $documento->id, LOGIN_DONO_DOWNLOAD_OCR, SENHA_DONO_DOWNLOAD_OCR))
        ->onConnection('database'));

    return DB::table('jobs')->where('queue', BaixarDocumentoParaOcrJob::FILA)->sole();
}

/** Uma passada do worker real na fila do download. */
function workerDownloadOcr(): void
{
    Artisan::call('queue:work', [
        'connection' => 'database',
        '--queue' => BaixarDocumentoParaOcrJob::FILA,
        '--once' => true,
        '--sleep' => 0,
    ]);
}

function estadoDownloadOcr(ProcessoDocumento $documento): array
{
    return Arr::only($documento->fresh()->getAttributes(), [
        'status', 'path', 'ocr_situacao', 'ocr_job_id', 'ocr_job_id_cancelar', 'ocr_enviado_em', 'ocr_erro',
    ]);
}

function expectSemOParDownloadOcr(string $texto): void
{
    expect($texto)->not->toContain(LOGIN_DONO_DOWNLOAD_OCR)
        ->and($texto)->not->toContain(SENHA_DONO_DOWNLOAD_OCR);
}

// ---------- declaração do job ----------

it('declara a fila ocr-download, ShouldBeEncrypted, 3 tentativas, backoff [10, 30, 60] e timeout 300', function () {
    $job = new BaixarDocumentoParaOcrJob(11, 22, LOGIN_DONO_DOWNLOAD_OCR, SENHA_DONO_DOWNLOAD_OCR);

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($job->queue)->toBe('ocr-download')
        ->and($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([10, 30, 60])
        ->and($job->timeout)->toBe(300)
        ->and($job->processamentoId)->toBe(11)
        ->and($job->documentoId)->toBe(22);
});

it('marca o par como parâmetro sensível no construtor', function () {
    $parametros = collect((new ReflectionMethod(BaixarDocumentoParaOcrJob::class, '__construct'))->getParameters())
        ->keyBy(fn (ReflectionParameter $parametro) => $parametro->getName());

    expect($parametros->keys()->all())->toBe(['processamentoId', 'documentoId', 'login', 'senha'])
        ->and($parametros['login']->getAttributes(SensitiveParameter::class))->toHaveCount(1)
        ->and($parametros['senha']->getAttributes(SensitiveParameter::class))->toHaveCount(1);
});

it('o payload em jobs.payload não tem o par, e o job decifrado chama o MNI com o par do dono (RF-09 AC c, RNF-01)', function () {
    simularWorkflowDownloadOcr();
    [$processamento, $documentos] = cenarioDownloadOcr(comAssinantes: false);

    $linha = despacharNaFilaDownloadOcr($processamento, $documentos->first());
    $payload = json_decode($linha->payload, true);

    expectSemOParDownloadOcr($linha->payload);
    expect($payload['data']['commandName'])->toBe(BaixarDocumentoParaOcrJob::class)
        ->and($payload['maxTries'])->toBe(3)
        ->and($payload['backoff'])->toBe('10,30,60')
        ->and($payload['timeout'])->toBe(300)
        // Cifrado: o command não é o objeto serializado (`O:...`).
        ->and(str_starts_with($payload['data']['command'], 'O:'))->toBeFalse()
        ->and(Crypt::decryptString($payload['data']['command']))->toContain(LOGIN_DONO_DOWNLOAD_OCR);

    workerDownloadOcr();

    // O envio despachado roda na fila `sync` e sai sem o par.
    expect($this->mni->requisicoes)->toHaveCount(1)
        ->and(DB::table('jobs')->where('queue', BaixarDocumentoParaOcrJob::FILA)->count())->toBe(0)
        ->and($documentos->first()->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_ENVIADO);
    expectSoapComOParDoDonoDownloadOcr($this->mni);
    Http::assertSentCount(1);
    expectSemOParDownloadOcr(json_encode(Http::recorded()->first()[0]->data()));
});

// ---------- download (RF-09) ----------

it('baixa o PDF com o par do dono, grava como o download de hoje e despacha o envio', function () {
    Queue::fake([EnviarDocumentoAoOcrJob::class, EnviarAvisoOcrJob::class]);
    [$processamento, $documentos, $processo] = cenarioDownloadOcr();
    $documento = $documentos->first();

    baixarParaOcr($processamento, $documento);

    $documento->refresh();
    $esperado = "documentos-processos/{$processo->numero_processo}/{$documento->id_documento}.pdf";

    expect($this->mni->requisicoes)->toHaveCount(1)
        ->and($this->mni->requisicoes[0]['parametros']['documento'])->toBe($documento->id_documento)
        ->and($documento->status)->toBe(ProcessoDocumento::STATUS_BAIXADO)
        ->and($documento->path)->toBe($esperado)
        ->and(Storage::disk('s3')->exists($esperado))->toBeTrue()
        // O download não muda a situação do OCR: quem muda é o envio.
        ->and($documento->ocr_situacao)->toBeNull();
    expectSoapComOParDoDonoDownloadOcr($this->mni);

    Queue::assertPushedOn('ocr-envio', EnviarDocumentoAoOcrJob::class, fn (EnviarDocumentoAoOcrJob $job) => $job->processamentoId === $processamento->id
        && $job->documentoId === $documento->id);
    Queue::assertPushedTimes(EnviarDocumentoAoOcrJob::class, 1);
});

it('depois do download do OCR, /documento/visualizar responde 200 com link e 0 SOAP (RF-09 AC a)', function (string $mimetype) {
    Queue::fake([EnviarDocumentoAoOcrJob::class, EnviarAvisoOcrJob::class]);
    [$processamento, $documentos, $processo] = cenarioDownloadOcr([['mimetype' => $mimetype]]);
    $documento = $documentos->first();

    baixarParaOcr($processamento, $documento);
    expect($this->mni->requisicoes)->toHaveCount(1);

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/documento/visualizar?'.http_build_query([
            'tribunal_id' => $processo->tribunal_id,
            'numero_processo' => $processo->numero_processo,
            'id_documento' => $documento->id_documento,
            'login_pje' => LOGIN_DONO_DOWNLOAD_OCR,
            'senha_pje' => SENHA_DONO_DOWNLOAD_OCR,
        ]))
        ->assertOk()
        ->assertJsonPath('documento.id_documento', $documento->id_documento)
        ->assertJsonPath('documento.link', fn (?string $link) => filled($link) && str_contains($link, $documento->fresh()->path));

    expect($this->mni->requisicoes)->toHaveCount(1);
})->with(['application/pdf', 'text/html']);

it('falha sem loginfailed em todas as tentativas: 3 SOAP com o backoff e com_erro + 1 só no failed() (RF-09)', function () {
    Http::fake();
    // Um 2º documento pendente mantém o processamento aberto depois do com_erro.
    [$processamento, $documentos, $processo] = cenarioDownloadOcr([[], []], comAssinantes: false);
    $documento = $documentos->first();
    $this->mni = simularIntegracaoMni(respostaMniDownloadOcr([
        $documento->id_documento => fn () => throw new SoapFault('Server', 'Could not connect to host'),
    ]));
    despacharNaFilaDownloadOcr($processamento, $documento);
    $falhasAntes = DB::table('failed_jobs')->count();
    $comErroAntes = contagemDownloadOcr($processo)->comErro;

    foreach ([10, 30] as $tentativa => $espera) {
        workerDownloadOcr();

        expect($this->mni->requisicoes)->toHaveCount($tentativa + 1)
            ->and($documento->fresh()->ocr_situacao)->toBeNull()
            ->and(contagemDownloadOcr($processo)->comErro)->toBe($comErroAntes);

        // Antes do backoff, a nova tentativa ainda não está disponível.
        $this->travel($espera - 1)->seconds();
        workerDownloadOcr();
        expect($this->mni->requisicoes)->toHaveCount($tentativa + 1);

        $this->travel(1)->seconds();
    }

    workerDownloadOcr();

    $documento->refresh();
    $falha = DB::table('failed_jobs')->orderByDesc('id')->first();

    expect($this->mni->requisicoes)->toHaveCount(3)
        ->and(DB::table('jobs')->where('queue', BaixarDocumentoParaOcrJob::FILA)->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe($falhasAntes + 1)
        ->and($documento->ocr_situacao)->toBe(ProcessoDocumento::OCR_ERRO)
        ->and($documento->ocr_erro)->toContain('esgotou as tentativas')
        ->and(contagemDownloadOcr($processo)->comErro)->toBe($comErroAntes + 1)
        ->and($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO);
    expectSoapComOParDoDonoDownloadOcr($this->mni);
    expectSemOParDownloadOcr($falha->payload);
    expectSemOParDownloadOcr($falha->exception);
    expectSemOParDownloadOcr((string) $documento->ocr_erro);
    // Sem binário, não há envio ao workflow.
    Http::assertNothingSent();
});

it('a falha comum registra warning com a classe e relança, sem o par no log', function () {
    Queue::fake([EnviarDocumentoAoOcrJob::class, EnviarAvisoOcrJob::class]);
    // Um 2º documento pendente mantém o processamento aberto depois do com_erro.
    [$processamento, $documentos] = cenarioDownloadOcr([[], []]);
    $documento = $documentos->first();
    $this->mni = simularIntegracaoMni(respostaMniDownloadOcr([
        $documento->id_documento => fn () => (object) ['sucesso' => false, 'mensagem' => 'Documento indisponível no momento'],
    ]));
    Log::spy();

    // Fila `sync`: sem nova tentativa, a falha chama o failed() e sobe até aqui.
    expect(fn () => baixarParaOcr($processamento, $documento))->toThrow(Exception::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $mensagem, array $contexto = []) => $mensagem === "[ProcessoDocumento:{$documento->id}] download do OCR falhou"
            && $contexto['erro'] === 'Exception'
            && $contexto['processamento_id'] === $processamento->id
            && ! str_contains(json_encode($contexto), LOGIN_DONO_DOWNLOAD_OCR)
            && ! str_contains(json_encode($contexto), SENHA_DONO_DOWNLOAD_OCR))
        ->once();

    // O failed() marca com_erro, e o erro definitivo é progresso: 1 aviso
    // em_andamento por assinante (RF-14).
    expect($documento->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_ERRO)
        ->and(ProcessoOcrAviso::query()->where('final', false)->count())->toBe(2)
        ->and(ProcessoOcrAviso::query()->where('final', true)->count())->toBe(0);
    Queue::assertNotPushed(EnviarDocumentoAoOcrJob::class);
});

// ---------- já baixado: direto ao envio (RF-09 AC d, e; R2-1, NC-2) ----------

it('documento baixado com o objeto no S3 vai direto ao envio, com 0 SOAP', function (string $mimetype, string $extensao, array $atributos) {
    Queue::fake([EnviarDocumentoAoOcrJob::class, EnviarAvisoOcrJob::class]);
    [$processamento, $documentos] = cenarioDownloadOcr([['mimetype' => $mimetype]]);
    $documento = gravarNoS3DownloadOcr($documentos->first(), $extensao, $atributos);
    $antes = estadoDownloadOcr($documento);

    baixarParaOcr($processamento, $documento);

    expect($this->mni->requisicoes)->toBe([])
        ->and(estadoDownloadOcr($documento))->toBe($antes);
    Queue::assertPushedOn('ocr-envio', EnviarDocumentoAoOcrJob::class, fn (EnviarDocumentoAoOcrJob $job) => $job->documentoId === $documento->id);
})->with([
    'PDF' => ['application/pdf', 'pdf', []],
    'PNG' => ['image/png', 'png', []],
    // Sem path_html nem conteudo_html: o baixarDocumento() reconsultaria o MNI.
    'HTML legado' => ['text/html', 'pdf', []],
    'com_erro devolvido pela reabertura' => ['application/pdf', 'pdf', [
        'ocr_situacao' => ProcessoDocumento::OCR_PENDENTE,
        'ocr_job_id_cancelar' => '1b2c3d4e-5f60-4a7b-8c9d-0e1f2a3b4c5d',
    ]],
]);

it('HTML legado pendente vai ao workflow pelo .pdf gravado, com 0 SOAP e 0 objetos novos (RF-09 AC d)', function () {
    Queue::fake([EnviarAvisoOcrJob::class]);
    simularWorkflowDownloadOcr();
    [$processamento, $documentos] = cenarioDownloadOcr([['mimetype' => 'text/html']]);
    $documento = gravarNoS3DownloadOcr($documentos->first(), 'pdf');
    $objetosAntes = Storage::disk('s3')->allFiles();

    baixarParaOcr($processamento, $documento);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === BASE_WORKFLOW_DOWNLOAD_OCR.'/documents/process'
        && str_contains($request['url'], $documento->path.'?')
        && str_ends_with($documento->path, '.pdf')
        && $request['filename'] === "{$documento->id_documento}.pdf");

    expect($this->mni->requisicoes)->toBe([])
        ->and(Storage::disk('s3')->allFiles())->toBe($objetosAntes)
        ->and($documento->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_ENVIADO);
});

it('baixado sem o objeto no S3 baixa de novo do MNI', function () {
    Queue::fake([EnviarDocumentoAoOcrJob::class, EnviarAvisoOcrJob::class]);
    [$processamento, $documentos] = cenarioDownloadOcr();
    $documento = gravarNoS3DownloadOcr($documentos->first(), 'pdf');
    Storage::disk('s3')->delete($documento->path);

    baixarParaOcr($processamento, $documento);

    expect($this->mni->requisicoes)->toHaveCount(1)
        ->and(Storage::disk('s3')->exists($documento->path))->toBeTrue();
    expectSoapComOParDoDonoDownloadOcr($this->mni);
    Queue::assertPushed(EnviarDocumentoAoOcrJob::class, 1);
});

// ---------- saídas sem SOAP ----------

it('processamento encerrado ou inexistente: 0 SOAP e 0 envio (RF-10 item 2)', function (?string $situacao) {
    Queue::fake([EnviarDocumentoAoOcrJob::class, EnviarAvisoOcrJob::class]);
    [$processamento, $documentos] = cenarioDownloadOcr();
    $documento = $documentos->first();

    if ($situacao === null) {
        $processamento->delete();
    } else {
        $processamento->update(['situacao' => $situacao, 'finalizado_em' => now()]);
    }

    $antes = estadoDownloadOcr($documento);

    baixarParaOcr($processamento, $documento);

    expect($this->mni->requisicoes)->toBe([])
        ->and(estadoDownloadOcr($documento))->toBe($antes);
    Queue::assertNotPushed(EnviarDocumentoAoOcrJob::class);
})->with([
    'falhou' => [ProcessoOcrProcessamento::SITUACAO_FALHOU],
    'concluido' => [ProcessoOcrProcessamento::SITUACAO_CONCLUIDO],
    'inexistente' => [null],
]);

it('documento processado ou de outro processo: 0 SOAP e 0 envio', function (string $caso) {
    Queue::fake([EnviarDocumentoAoOcrJob::class, EnviarAvisoOcrJob::class]);
    [$processamento, $documentos] = cenarioDownloadOcr();
    $documento = $documentos->first();

    if ($caso === 'processado') {
        $documento->update(['ocr_situacao' => ProcessoDocumento::OCR_PROCESSADO]);
    } else {
        [, $outros] = cenarioDownloadOcr();
        $documento = $outros->first();
    }

    baixarParaOcr($processamento, $documento);

    expect($this->mni->requisicoes)->toBe([]);
    Queue::assertNotPushed(EnviarDocumentoAoOcrJob::class);
})->with(['processado', 'de outro processo']);

it('failed() com o processamento encerrado não muda o documento', function () {
    Queue::fake([EnviarDocumentoAoOcrJob::class, EnviarAvisoOcrJob::class]);
    [$processamento, $documentos, $processo] = cenarioDownloadOcr();
    $documento = $documentos->first();
    $processamento->update(['situacao' => ProcessoOcrProcessamento::SITUACAO_FALHOU, 'finalizado_em' => now()]);
    $antes = estadoDownloadOcr($documento);

    (new BaixarDocumentoParaOcrJob($processamento->id, $documento->id, LOGIN_DONO_DOWNLOAD_OCR, SENHA_DONO_DOWNLOAD_OCR))
        ->failed(new RuntimeException('tentativas esgotadas'));

    expect(estadoDownloadOcr($documento))->toBe($antes)
        ->and(contagemDownloadOcr($processo)->comErro)->toBe(0)
        ->and(ProcessoOcrAviso::count())->toBe(0);
});

// ---------- recusa do par (RF-10) ----------

it('recusa do par em cada forma crua: encerra falhou por papel, sem relançar, sem com_erro e com o documento pendente', function (string $forma) {
    Queue::fake([EnviarDocumentoAoOcrJob::class, EnviarAvisoOcrJob::class]);
    [$processamento, $documentos, $processo] = cenarioDownloadOcr(caronas: 2);
    $documento = $documentos->first();
    $this->mni = simularIntegracaoMni(respostaMniDownloadOcr([
        $documento->id_documento => match ($forma) {
            'sucesso_false' => fn () => (object) ['sucesso' => false, 'mensagem' => TEXTO_RECUSA_DOWNLOAD_OCR],
            'excecao_do_recibo' => fn () => throw new Exception(TEXTO_RECUSA_DOWNLOAD_OCR, 0),
            'soap_fault' => fn () => throw new SoapFault('Server', TEXTO_RECUSA_DOWNLOAD_OCR),
        },
    ]));
    $falhasAntes = DB::table('failed_jobs')->count();

    baixarParaOcr($processamento, $documento);

    $processamento->refresh();
    $finais = ProcessoOcrAviso::query()->with('assinante')->where('final', true)->get();

    expect($this->mni->requisicoes)->toHaveCount(1)
        ->and($processamento->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_FALHOU)
        ->and($processamento->codigo_falha)->toBe('credencial_pje_recusada')
        ->and($documento->fresh()->ocr_situacao)->toBeNull()
        ->and(contagemDownloadOcr($processo)->comErro)->toBe(0)
        ->and(contagemDownloadOcr($processo)->pendentes)->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe($falhasAntes)
        ->and($finais)->toHaveCount(3)
        ->and($finais->every(fn (ProcessoOcrAviso $aviso) => $aviso->payload['situacao'] === 'falhou'))->toBeTrue()
        ->and($finais->first(fn (ProcessoOcrAviso $aviso) => $aviso->assinante->dono)->payload['codigo'])->toBe('credencial_pje_recusada')
        ->and($finais->reject(fn (ProcessoOcrAviso $aviso) => $aviso->assinante->dono)->pluck('payload.codigo')->unique()->all())
        ->toBe(['credencial_de_outro_pedido_recusada']);
    expectSoapComOParDoDonoDownloadOcr($this->mni);
    Queue::assertNotPushed(EnviarDocumentoAoOcrJob::class);
})->with([
    'sucesso false' => 'sucesso_false',
    'exceção do recibo' => 'excecao_do_recibo',
    'SoapFault' => 'soap_fault',
]);

it('10 documentos com recusa a partir do 4º download: 0 SOAP depois, com_erro sem mudança, 7 pendentes e 1 final falhou por papel (RF-10 AC)', function () {
    Queue::fake([EnviarAvisoOcrJob::class]);
    simularWorkflowDownloadOcr();
    [$processamento, $documentos, $processo] = cenarioDownloadOcr(array_fill(0, 10, []), caronas: 2);

    // O tribunal passa a recusar o par a partir do 4º download.
    $this->mni = simularIntegracaoMni(respostaMniDownloadOcr(
        $documentos->slice(3)->mapWithKeys(fn (ProcessoDocumento $documento) => [
            $documento->id_documento => fn () => (object) ['sucesso' => false, 'mensagem' => TEXTO_RECUSA_DOWNLOAD_OCR],
        ])->all(),
    ));
    $falhasAntes = DB::table('failed_jobs')->count();

    foreach ($documentos as $documento) {
        baixarParaOcr($processamento, $documento);
    }

    $processamento->refresh();
    $contagem = contagemDownloadOcr($processo);
    $finais = ProcessoOcrAviso::query()->with('assinante')->where('final', true)->get();
    $documentosSoap = array_map(fn (array $requisicao) => $requisicao['parametros']['documento'], $this->mni->requisicoes);

    expect($documentosSoap)->toBe($documentos->take(4)->pluck('id_documento')->all())
        ->and($processamento->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_FALHOU)
        ->and($processamento->codigo_falha)->toBe('credencial_pje_recusada')
        // Os 3 baixados antes da recusa foram enviados e aguardam retorno.
        ->and($contagem->aguardando)->toBe(3)
        ->and($contagem->pendentes)->toBe(7)
        ->and($contagem->comErro)->toBe(0)
        ->and($contagem->processados + $contagem->comErro)->toBeLessThanOrEqual($contagem->total)
        ->and($documentos->slice(3)->every(fn (ProcessoDocumento $documento) => $documento->fresh()->ocr_situacao === null))->toBeTrue()
        ->and(DB::table('failed_jobs')->count())->toBe($falhasAntes)
        ->and($finais)->toHaveCount(3)
        ->and($finais->pluck('assinante_id')->unique())->toHaveCount(3)
        ->and($finais->first(fn (ProcessoOcrAviso $aviso) => $aviso->assinante->dono)->payload)->toMatchArray([
            'situacao' => 'falhou',
            'codigo' => 'credencial_pje_recusada',
            'total' => 10,
            'com_erro' => 0,
        ])
        ->and($finais->reject(fn (ProcessoOcrAviso $aviso) => $aviso->assinante->dono)->every(
            fn (ProcessoOcrAviso $aviso) => $aviso->payload['situacao'] === 'falhou'
                && $aviso->payload['codigo'] === 'credencial_de_outro_pedido_recusada'
        ))->toBeTrue();
    expectSoapComOParDoDonoDownloadOcr($this->mni);

    // O envio sai só para os 3 baixados, sem o par.
    Http::assertSentCount(3);
    Http::assertNotSent(fn (Request $request) => str_contains(json_encode($request->data()), LOGIN_DONO_DOWNLOAD_OCR)
        || str_contains(json_encode($request->data()), SENHA_DONO_DOWNLOAD_OCR));
});

// ---------- mimetype vazio: revelação pelo tribunal (T11; RF-09 a RF-13, RNF-05) ----------

/**
 * O tribunal simulado devolve o documento `$documento` com o mimetype
 * `$revelado` (o da lista é `''`). `$antes` roda antes da resposta, como uma
 * gravação da lista concorrente com a chamada SOAP.
 *
 * @return array<int, Closure> no formato de `$falhas` de respostaMniDownloadOcr
 */
function revelacaoMniDownloadOcr(ProcessoDocumento $documento, string $revelado, ?Closure $antes = null): array
{
    return [$documento->id_documento => function () use ($documento, $revelado, $antes) {
        if ($antes !== null) {
            $antes();
        }

        return (object) ['sucesso' => true, 'processo' => (object) ['documento' => documentoMniFake($documento->id_documento, [
            'mimetype' => $revelado,
            'conteudo' => $revelado === 'text/html'
                ? "<html><body><p>Documento revelado {$documento->id_documento}</p></body></html>"
                : "conteudo-revelado-{$documento->id_documento}",
        ])]];
    }];
}

function caminhoDownloadOcr(Processo $processo, ProcessoDocumento $documento, string $extensao): string
{
    return "documentos-processos/{$processo->numero_processo}/{$documento->id_documento}.{$extensao}";
}

/** @return \Illuminate\Support\Collection<int, ProcessoOcrAviso> */
function avisosDoAssinanteDownloadOcr(ProcessoOcrAssinante $assinante): \Illuminate\Support\Collection
{
    return ProcessoOcrAviso::query()->where('assinante_id', $assinante->id)->orderBy('id')->get();
}

/** @return \Illuminate\Support\Collection<int, ProcessoOcrAssinante> */
function assinantesDownloadOcr(ProcessoOcrProcessamento $processamento): \Illuminate\Support\Collection
{
    return ProcessoOcrAssinante::query()->where('processamento_id', $processamento->id)->orderBy('id')->get();
}

/**
 * As 2 invariantes de RF-07 em todo aviso: `processados + com_erro ≤ total`
 * e `total + ignorados` = documentos do processo no banco.
 */
function expectInvariantesAvisoDownloadOcr(array $payload, Processo $processo): void
{
    expect($payload['processados'] + $payload['com_erro'])->toBeLessThanOrEqual($payload['total'])
        ->and($payload['total'] + $payload['ignorados'])
        ->toBe(ProcessoDocumento::query()->where('processo_id', $processo->id)->count());
}

it('vazio pendente revelado legível: grava o mimetype e o objeto pelo revelado, 1 SOAP com o par do dono, 1 envio em ocr-envio e 0 avisos (RF-09, RF-10)', function (string $revelado) {
    Queue::fake([EnviarDocumentoAoOcrJob::class, EnviarAvisoOcrJob::class]);
    [$processamento, $documentos, $processo] = cenarioDownloadOcr([['mimetype' => '']]);
    $documento = $documentos->first();
    $this->mni = simularIntegracaoMni(respostaMniDownloadOcr(revelacaoMniDownloadOcr($documento, $revelado)));
    $progressoAntes = $processamento->fresh()->ultimo_progresso_em;

    $this->travel(3)->minutes();
    baixarParaOcr($processamento, $documento);

    $documento->refresh();
    $pdf = caminhoDownloadOcr($processo, $documento, 'pdf');

    expect($documento->mimetype)->toBe($revelado)
        ->and($documento->path)->toBe($pdf)
        ->and($documento->status)->toBe(ProcessoDocumento::STATUS_BAIXADO)
        ->and(Storage::disk('s3')->exists($pdf))->toBeTrue()
        // A revelação não muda a situação do OCR: quem muda é o envio.
        ->and($documento->ocr_situacao)->toBeNull()
        ->and($this->mni->requisicoes)->toHaveCount(1)
        ->and($this->mni->requisicoes[0]['parametros']['documento'])->toBe($documento->id_documento)
        // RF-10: a revelação não é progresso.
        ->and(ProcessoOcrAviso::count())->toBe(0)
        ->and($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO)
        ->and($processamento->fresh()->ultimo_progresso_em?->toIso8601String())->toBe($progressoAntes?->toIso8601String());
    expectSoapComOParDoDonoDownloadOcr($this->mni);

    if ($revelado === 'text/html') {
        expect($documento->path_html)->toBe(caminhoDownloadOcr($processo, $documento, 'html'))
            ->and(Storage::disk('s3')->exists($documento->path_html))->toBeTrue();
    }

    Queue::assertPushedOn('ocr-envio', EnviarDocumentoAoOcrJob::class, fn (EnviarDocumentoAoOcrJob $job) => $job->processamentoId === $processamento->id
        && $job->documentoId === $documento->id);
    Queue::assertPushedTimes(EnviarDocumentoAoOcrJob::class, 1);
})->with([
    'PDF' => 'application/pdf',
    'HTML' => 'text/html',
]);

it('vazio já baixado com o .dat no S3: o atalho do S3 não vale, e o tribunal é chamado 1 vez com o par do dono (RF-09 AC b, RF-13)', function () {
    Queue::fake([EnviarDocumentoAoOcrJob::class, EnviarAvisoOcrJob::class]);
    [$processamento, $documentos, $processo] = cenarioDownloadOcr([['mimetype' => '']]);
    $documento = gravarNoS3DownloadOcr($documentos->first(), 'dat');
    $this->mni = simularIntegracaoMni(respostaMniDownloadOcr(revelacaoMniDownloadOcr($documento, 'application/pdf')));

    baixarParaOcr($processamento, $documento);

    $documento->refresh();

    expect($this->mni->requisicoes)->toHaveCount(1)
        ->and($documento->mimetype)->toBe('application/pdf')
        ->and($documento->path)->toBe(caminhoDownloadOcr($processo, $documento, 'pdf'))
        ->and($documento->status)->toBe(ProcessoDocumento::STATUS_BAIXADO);
    expectSoapComOParDoDonoDownloadOcr($this->mni);
    Queue::assertPushedTimes(EnviarDocumentoAoOcrJob::class, 1);
});

it('vazio revelado vídeo como único pendente: path .mp4, 0 envio, 1 em_andamento por assinante com total −1 e ignorados +1 e depois o final concluido (RF-11)', function () {
    Queue::fake([EnviarDocumentoAoOcrJob::class, EnviarAvisoOcrJob::class]);
    [$processamento, $documentos, $processo] = cenarioDownloadOcr([
        ['mimetype' => 'application/pdf', 'ocr_situacao' => ProcessoDocumento::OCR_PROCESSADO],
        ['mimetype' => ''],
    ]);
    $documento = $documentos[1];
    $this->mni = simularIntegracaoMni(respostaMniDownloadOcr(revelacaoMniDownloadOcr($documento, 'video/mp4')));
    $antes = contagemDownloadOcr($processo);

    expect([$antes->total, $antes->ignorados])->toBe([2, 0]);

    baixarParaOcr($processamento, $documento);

    $documento->refresh();
    $mp4 = caminhoDownloadOcr($processo, $documento, 'mp4');

    expect($documento->mimetype)->toBe('video/mp4')
        ->and($documento->path)->toBe($mp4)
        ->and(Storage::disk('s3')->exists($mp4))->toBeTrue()
        ->and($documento->status)->toBe(ProcessoDocumento::STATUS_BAIXADO)
        ->and($this->mni->requisicoes)->toHaveCount(1)
        ->and($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_CONCLUIDO);
    Queue::assertNotPushed(EnviarDocumentoAoOcrJob::class);

    $assinantes = assinantesDownloadOcr($processamento);

    expect($assinantes)->toHaveCount(2);

    foreach ($assinantes as $assinante) {
        $avisos = avisosDoAssinanteDownloadOcr($assinante);

        expect($avisos)->toHaveCount(2)
            ->and($avisos[0]->final)->toBeFalse()
            ->and($avisos[0]->payload)->toMatchArray([
                'situacao' => 'em_andamento',
                'total' => $antes->total - 1,
                'ignorados' => $antes->ignorados + 1,
            ])
            ->and($avisos[1]->final)->toBeTrue()
            ->and($avisos[1]->payload)->toMatchArray([
                'situacao' => 'concluido',
                'total' => $antes->total - 1,
                'ignorados' => $antes->ignorados + 1,
            ]);

        $avisos->each(fn (ProcessoOcrAviso $aviso) => expectInvariantesAvisoDownloadOcr($aviso->payload, $processo));
    }
});

it('vazio que o tribunal também não informa: erro com o texto fixo, objeto .dat, 0 envio e aviso com com_erro +1 (RF-12)', function () {
    Queue::fake([EnviarDocumentoAoOcrJob::class, EnviarAvisoOcrJob::class]);
    // Um 2º documento pendente mantém o processamento aberto depois do erro.
    [$processamento, $documentos, $processo] = cenarioDownloadOcr([['mimetype' => ''], []]);
    $documento = $documentos->first();
    $this->mni = simularIntegracaoMni(respostaMniDownloadOcr(revelacaoMniDownloadOcr($documento, '')));
    $antes = contagemDownloadOcr($processo);

    baixarParaOcr($processamento, $documento);

    $documento->refresh();

    expect($documento->mimetype)->toBe('')
        ->and($documento->ocr_situacao)->toBe(ProcessoDocumento::OCR_ERRO)
        ->and($documento->ocr_erro)->toBe('mimetype não informado pelo tribunal')
        ->and($documento->path)->toBe(caminhoDownloadOcr($processo, $documento, 'dat'))
        ->and($documento->status)->toBe(ProcessoDocumento::STATUS_BAIXADO)
        ->and($this->mni->requisicoes)->toHaveCount(1)
        ->and($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO);
    Queue::assertNotPushed(EnviarDocumentoAoOcrJob::class);

    foreach (assinantesDownloadOcr($processamento) as $assinante) {
        $avisos = avisosDoAssinanteDownloadOcr($assinante);

        expect($avisos)->toHaveCount(1)
            ->and($avisos[0]->final)->toBeFalse()
            ->and($avisos[0]->payload)->toMatchArray([
                'situacao' => 'em_andamento',
                'total' => $antes->total,
                'com_erro' => $antes->comErro + 1,
                'ignorados' => $antes->ignorados,
            ]);
        expectInvariantesAvisoDownloadOcr($avisos[0]->payload, $processo);
    }
});

it('audio/mpeg baixado no S3 sem mais nada a ler: 0 SOAP, 0 envio, 0 em_andamento e concluido com 1 final por assinante (RF-13, Q-05)', function () {
    Queue::fake([EnviarDocumentoAoOcrJob::class, EnviarAvisoOcrJob::class]);
    [$processamento, $documentos, $processo] = cenarioDownloadOcr([
        ['mimetype' => 'application/pdf', 'ocr_situacao' => ProcessoDocumento::OCR_PROCESSADO],
        ['mimetype' => 'audio/mpeg'],
    ]);
    $documento = gravarNoS3DownloadOcr($documentos[1], 'mp3');
    $antes = estadoDownloadOcr($documento);

    baixarParaOcr($processamento, $documento);

    $finais = ProcessoOcrAviso::query()->where('final', true)->get();

    expect($this->mni->requisicoes)->toBe([])
        ->and(estadoDownloadOcr($documento))->toBe($antes)
        ->and($documento->fresh()->mimetype)->toBe('audio/mpeg')
        ->and($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_CONCLUIDO)
        ->and(ProcessoOcrAviso::query()->where('final', false)->count())->toBe(0)
        ->and($finais)->toHaveCount(2)
        ->and($finais->pluck('assinante_id')->unique())->toHaveCount(2)
        ->and($finais->every(fn (ProcessoOcrAviso $aviso) => $aviso->payload['situacao'] === 'concluido'))->toBeTrue();
    $finais->each(fn (ProcessoOcrAviso $aviso) => expectInvariantesAvisoDownloadOcr($aviso->payload, $processo));
    Queue::assertNotPushed(EnviarDocumentoAoOcrJob::class);
});

it('audio/mpeg baixado no S3 com outro documento pendente: 0 SOAP, 0 envio, 0 avisos e o processamento continua em_andamento (RF-13, Q-05)', function () {
    Queue::fake([EnviarDocumentoAoOcrJob::class, EnviarAvisoOcrJob::class]);
    [$processamento, $documentos] = cenarioDownloadOcr([[], ['mimetype' => 'audio/mpeg']]);
    $documento = gravarNoS3DownloadOcr($documentos[1], 'mp3');
    $antes = estadoDownloadOcr($documento);

    baixarParaOcr($processamento, $documento);

    expect($this->mni->requisicoes)->toBe([])
        ->and(estadoDownloadOcr($documento))->toBe($antes)
        ->and(ProcessoOcrAviso::count())->toBe(0)
        ->and($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO)
        ->and($processamento->fresh()->finalizado_em)->toBeNull();
    Queue::assertNotPushed(EnviarDocumentoAoOcrJob::class);
});

it('recusa do par no vazio: encerra falhou por papel, sem relançar, sem com_erro e com o documento vazio e pendente', function (string $forma) {
    Queue::fake([EnviarDocumentoAoOcrJob::class, EnviarAvisoOcrJob::class]);
    [$processamento, $documentos, $processo] = cenarioDownloadOcr([['mimetype' => '']], caronas: 2);
    $documento = $documentos->first();
    $this->mni = simularIntegracaoMni(respostaMniDownloadOcr([
        $documento->id_documento => match ($forma) {
            'sucesso_false' => fn () => (object) ['sucesso' => false, 'mensagem' => TEXTO_RECUSA_DOWNLOAD_OCR],
            'excecao_do_recibo' => fn () => throw new Exception(TEXTO_RECUSA_DOWNLOAD_OCR, 0),
            'soap_fault' => fn () => throw new SoapFault('Server', TEXTO_RECUSA_DOWNLOAD_OCR),
        },
    ]));
    $antes = estadoDownloadOcr($documento);
    $falhasAntes = DB::table('failed_jobs')->count();

    baixarParaOcr($processamento, $documento);

    $processamento->refresh();
    $finais = ProcessoOcrAviso::query()->with('assinante')->where('final', true)->get();

    expect($this->mni->requisicoes)->toHaveCount(1)
        ->and($processamento->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_FALHOU)
        ->and($processamento->codigo_falha)->toBe('credencial_pje_recusada')
        ->and($documento->fresh()->mimetype)->toBe('')
        ->and(estadoDownloadOcr($documento))->toBe($antes)
        ->and($documento->fresh()->ocr_situacao)->toBeNull()
        ->and(contagemDownloadOcr($processo)->comErro)->toBe(0)
        ->and(contagemDownloadOcr($processo)->pendentes)->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe($falhasAntes)
        ->and(ProcessoOcrAviso::query()->where('final', false)->count())->toBe(0)
        ->and($finais)->toHaveCount(3)
        ->and($finais->every(fn (ProcessoOcrAviso $aviso) => $aviso->payload['situacao'] === 'falhou'))->toBeTrue()
        ->and($finais->first(fn (ProcessoOcrAviso $aviso) => $aviso->assinante->dono)->payload['codigo'])->toBe('credencial_pje_recusada')
        ->and($finais->reject(fn (ProcessoOcrAviso $aviso) => $aviso->assinante->dono)->pluck('payload.codigo')->unique()->all())
        ->toBe(['credencial_de_outro_pedido_recusada']);
    expectSoapComOParDoDonoDownloadOcr($this->mni);
    Queue::assertNotPushed(EnviarDocumentoAoOcrJob::class);
})->with([
    'sucesso false' => 'sucesso_false',
    'exceção do recibo' => 'excecao_do_recibo',
    'SoapFault' => 'soap_fault',
]);

it('falha comum no vazio: warning com a classe, relança, e o failed() registra o erro com o mimetype ainda vazio', function () {
    Queue::fake([EnviarDocumentoAoOcrJob::class, EnviarAvisoOcrJob::class]);
    // Um 2º documento pendente mantém o processamento aberto depois do com_erro.
    [$processamento, $documentos] = cenarioDownloadOcr([['mimetype' => ''], []]);
    $documento = $documentos->first();
    $this->mni = simularIntegracaoMni(respostaMniDownloadOcr([
        $documento->id_documento => fn () => throw new SoapFault('Server', 'Could not connect to host'),
    ]));
    Log::spy();

    // Fila `sync`: sem nova tentativa, a falha chama o failed() e sobe até aqui.
    expect(fn () => baixarParaOcr($processamento, $documento))->toThrow(Exception::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $mensagem, array $contexto = []) => $mensagem === "[ProcessoDocumento:{$documento->id}] download do OCR falhou"
            && $contexto['erro'] === 'Exception'
            && $contexto['processamento_id'] === $processamento->id
            && ! str_contains(json_encode($contexto), LOGIN_DONO_DOWNLOAD_OCR)
            && ! str_contains(json_encode($contexto), SENHA_DONO_DOWNLOAD_OCR))
        ->once();

    $documento->refresh();

    expect($this->mni->requisicoes)->toHaveCount(1)
        ->and($documento->mimetype)->toBe('')
        ->and($documento->ocr_situacao)->toBe(ProcessoDocumento::OCR_ERRO)
        ->and($documento->ocr_erro)->toContain('esgotou as tentativas')
        ->and($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO);
    Queue::assertNotPushed(EnviarDocumentoAoOcrJob::class);
});

it('o job 2 vezes no mesmo vazio revelado vídeo, com outro documento pendente: 1 aviso, 0 envios e 1 SOAP no total (RNF-05)', function () {
    Queue::fake([EnviarDocumentoAoOcrJob::class, EnviarAvisoOcrJob::class]);
    [$processamento, $documentos] = cenarioDownloadOcr([['mimetype' => ''], []], caronas: 0);
    $documento = $documentos->first();
    $this->mni = simularIntegracaoMni(respostaMniDownloadOcr(revelacaoMniDownloadOcr($documento, 'video/mp4')));

    baixarParaOcr($processamento, $documento);
    baixarParaOcr($processamento, $documento);

    expect($this->mni->requisicoes)->toHaveCount(1)
        ->and(ProcessoOcrAviso::count())->toBe(1)
        ->and(ProcessoOcrAviso::query()->sole()->final)->toBeFalse()
        ->and($documento->fresh()->mimetype)->toBe('video/mp4')
        ->and($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO);
    Queue::assertNotPushed(EnviarDocumentoAoOcrJob::class);
});

it('lista regrava o vazio como vídeo durante a revelação (JaRevelado): segue pelo gravado, 1 SOAP, 0 envio, 0 em_andamento e concluido se nada resta (RNF-05, Q-05)', function () {
    Queue::fake([EnviarDocumentoAoOcrJob::class, EnviarAvisoOcrJob::class]);
    [$processamento, $documentos] = cenarioDownloadOcr([['mimetype' => '']]);
    $documento = $documentos->first();
    $this->mni = simularIntegracaoMni(respostaMniDownloadOcr(revelacaoMniDownloadOcr(
        $documento,
        'application/pdf',
        fn () => ProcessoDocumento::query()->whereKey($documento->id)->update(['mimetype' => 'video/mp4']),
    )));

    baixarParaOcr($processamento, $documento);

    expect($this->mni->requisicoes)->toHaveCount(1)
        // O valor da lista não é trocado pelo revelado.
        ->and($documento->fresh()->mimetype)->toBe('video/mp4')
        ->and($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_CONCLUIDO)
        ->and(ProcessoOcrAviso::query()->where('final', false)->count())->toBe(0)
        ->and(ProcessoOcrAviso::query()->where('final', true)->count())->toBe(2);
    Queue::assertNotPushed(EnviarDocumentoAoOcrJob::class);
});

it('lista regrava o vazio como PDF durante a revelação (JaRevelado): segue ao envio pelo objeto gravado, sem 2ª chamada SOAP', function () {
    Queue::fake([EnviarDocumentoAoOcrJob::class, EnviarAvisoOcrJob::class]);
    [$processamento, $documentos] = cenarioDownloadOcr([['mimetype' => '']]);
    $documento = $documentos->first();
    $this->mni = simularIntegracaoMni(respostaMniDownloadOcr(revelacaoMniDownloadOcr(
        $documento,
        'application/pdf',
        fn () => ProcessoDocumento::query()->whereKey($documento->id)->update(['mimetype' => 'application/pdf']),
    )));

    baixarParaOcr($processamento, $documento);

    expect($this->mni->requisicoes)->toHaveCount(1)
        ->and($documento->fresh()->mimetype)->toBe('application/pdf')
        ->and($documento->fresh()->status)->toBe(ProcessoDocumento::STATUS_BAIXADO)
        ->and(ProcessoOcrAviso::count())->toBe(0);
    Queue::assertPushedOn('ocr-envio', EnviarDocumentoAoOcrJob::class, fn (EnviarDocumentoAoOcrJob $job) => $job->documentoId === $documento->id);
    Queue::assertPushedTimes(EnviarDocumentoAoOcrJob::class, 1);
});
