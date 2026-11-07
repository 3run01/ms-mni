<?php

use App\Exceptions\FalhaTransitoriaWorkflowOcrException;
use App\Jobs\EnviarAvisoOcrJob;
use App\Jobs\EnviarDocumentoAoOcrJob;
use App\Jobs\VerificarReenvioOcrJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\ProcessoOcrAssinante;
use App\Models\ProcessoOcrAviso;
use App\Models\ProcessoOcrProcessamento;
use App\Services\Ocr\ContagemOcr;
use App\Services\Ocr\ContagemOcrService;
use App\Services\Ocr\WebhookOcrUrl;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * Consulta do job antigo antes do reenvio (T16; D9, RF-18 item 3 e AC c,
 * d, l, m, n, o, p, CT-05, CT-07), com a fila `sync` do phpunit.xml: o
 * VerificarReenvioOcrJob roda, e o EnviarDocumentoAoOcrJob que ele despacha
 * roda em seguida, como a fila `ocr-envio` faria. Só os avisos ficam na
 * fila simulada, contados pelas linhas de processo_ocr_avisos.
 */

uses(DatabaseTransactions::class);

const BASE_WORKFLOW_REENVIO_OCR_JOB = 'http://n8n.test/webhook/juridico-ocr';
const TOKEN_WORKFLOW_REENVIO_OCR_JOB = 'token-workflow-reenvio-6b3';
const BASE_WEBHOOK_REENVIO_OCR_JOB = 'http://host.docker.internal:8002';
const JOB_ANTIGO_REENVIO_OCR_JOB = '5d4c3b2a-1f0e-4d9c-8b7a-6f5e4d3c2b1a';
const JOB_NOVO_REENVIO_OCR_JOB = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
const LOGIN_REENVIO_OCR_JOB = 'login-reenvio-2f6';
const SENHA_REENVIO_OCR_JOB = 'senha-reenvio-4a9';

beforeEach(function () {
    Queue::fake([EnviarAvisoOcrJob::class]);
    $this->freezeSecond();
    Storage::fake('s3');

    config()->set('services.n8n_ocr.url', BASE_WORKFLOW_REENVIO_OCR_JOB);
    config()->set('services.n8n_ocr.token', TOKEN_WORKFLOW_REENVIO_OCR_JOB);
    config()->set('services.n8n_ocr.base_conhecimento', 'base-processos');
    config()->set('services.n8n_ocr.webhook_base_url', BASE_WEBHOOK_REENVIO_OCR_JOB);
    config()->set('services.n8n_ocr.prazo_sem_progresso_minutos', 25);
    config()->set('services.n8n_ocr.idade_maxima_sem_retorno_minutos', 20);
    config()->set('services.n8n_ocr.idade_maxima_absoluta_minutos', 60);
    definirCredenciaisPadrao(LOGIN_REENVIO_OCR_JOB, SENHA_REENVIO_OCR_JOB);

    // O reenvio vai direto do S3 (RF-09, R2-1): toda chamada SOAP fica registrada.
    $this->mni = simularIntegracaoMni(fn () => throw new RuntimeException('o reenvio ao OCR não chama o MNI'));
});

/**
 * Processamento em andamento com 2 assinantes; o documento aguardando
 * retorno do job antigo, enviado há `$idade` minutos e já no S3; e um 2º
 * documento pendente, para que o processamento não termine com o retorno.
 *
 * @return array{0: ProcessoOcrProcessamento, 1: ProcessoDocumento, 2: Processo}
 */
function cenarioReenvioOcrJob(int $idade = 21, array $atributos = []): array
{
    [$processo, $documentos] = criarDocumentosLote([
        ['ocr_situacao' => null],
        ['ocr_situacao' => null],
    ]);
    $documento = $documentos->first();

    $path = "documentos-processos/{$processo->numero_processo}/{$documento->id_documento}.pdf";
    Storage::disk('s3')->put($path, "conteudo-{$documento->id_documento}");
    $documento->update(array_merge([
        'status' => ProcessoDocumento::STATUS_BAIXADO,
        'path' => $path,
        'ocr_situacao' => ProcessoDocumento::OCR_ENVIADO,
        'ocr_job_id' => JOB_ANTIGO_REENVIO_OCR_JOB,
        'ocr_enviado_em' => now()->subMinutes($idade),
    ], $atributos));

    $processamento = ProcessoOcrProcessamento::create(['processo_id' => $processo->id]);

    foreach (['tok-dono', 'tok-carona'] as $i => $token) {
        ProcessoOcrAssinante::create([
            'processamento_id' => $processamento->id,
            'dono' => $i === 0,
            'callback_url' => "https://sistema.example/mni/p/{$processamento->id}/progresso?assinante={$token}",
            'callback_token' => $token,
        ]);
    }

    return [$processamento, $documento->fresh(), $processo];
}

/**
 * Workflow simulado por método. GET (CT-05): `'404'`, um `status` do job ou
 * a resposta dada. DELETE (CT-07) responde 200 e POST (CT-03) aceita com o
 * job novo, salvo resposta dada.
 */
function simularWorkflowReenvioOcrJob(string|Closure $get, ?Closure $delete = null, ?Closure $post = null): void
{
    $delete ??= fn () => Http::response(['job_id' => JOB_ANTIGO_REENVIO_OCR_JOB, 'deleted_chunks' => 7], 200);
    $post ??= fn () => Http::response(['id' => JOB_NOVO_REENVIO_OCR_JOB, 'status' => 'pending', 'error' => null], 202);

    Http::fake(function (Request $request) use ($get, $delete, $post) {
        return match ($request->method()) {
            'GET' => match (true) {
                $get instanceof Closure => $get($request),
                $get === '404' => Http::response(['detail' => 'Job não encontrado'], 404),
                default => Http::response(['id' => JOB_ANTIGO_REENVIO_OCR_JOB, 'status' => $get, 'error' => null], 200),
            },
            'DELETE' => $delete($request),
            'POST' => $post($request),
        };
    });
}

/**
 * As chamadas ao workflow, na ordem: "MÉTODO caminho".
 *
 * @return list<string>
 */
function chamadasReenvioOcrJob(): array
{
    return Http::recorded()
        ->map(fn (array $par) => $par[0]->method().' '.Str::after($par[0]->url(), BASE_WORKFLOW_REENVIO_OCR_JOB))
        ->values()
        ->all();
}

function verificarReenvioOcrJob(ProcessoOcrProcessamento $processamento, ProcessoDocumento $documento): void
{
    VerificarReenvioOcrJob::dispatch($processamento->id, $documento->id);
}

function contagemReenvioOcrJob(Processo $processo): ContagemOcr
{
    return app(ContagemOcrService::class)->contar($processo->id);
}

/** O retorno do workflow como o n8n manda, sem X-API-Token. */
function webhookReenvioOcrJob(ProcessoDocumento $documento, string $jobId, string $status = 'success'): TestResponse
{
    return test()->postJson(WebhookOcrUrl::gerar($documento), ['status' => $status, 'job_id' => $jobId], ['Accept' => '*/*']);
}

function estadoReenvioOcrJob(ProcessoDocumento $documento): array
{
    return Arr::only($documento->fresh()->getAttributes(), [
        'status', 'ocr_situacao', 'ocr_job_id', 'ocr_job_id_cancelar', 'ocr_enviado_em', 'ocr_concluido_em', 'ocr_erro',
    ]);
}

const GET_ANTIGO_REENVIO_OCR_JOB = 'GET /jobs/'.JOB_ANTIGO_REENVIO_OCR_JOB;
const DELETE_ANTIGO_REENVIO_OCR_JOB = 'DELETE /jobs/'.JOB_ANTIGO_REENVIO_OCR_JOB.'/chunks';
const POST_REENVIO_OCR_JOB = 'POST /documents/process';

// ---------- declaração do job ----------

it('declara a fila ocr-envio, ShouldBeUnique por documento por 120 s, 1 tentativa e timeout 60', function () {
    $job = new VerificarReenvioOcrJob(11, 22);

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job)->not->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($job->queue)->toBe('ocr-envio')
        ->and($job->uniqueId())->toBe('22')
        ->and($job->uniqueFor)->toBe(120)
        ->and($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(60)
        ->and(method_exists($job, 'backoff'))->toBeFalse();
});

it('o job recebe só os ids, e o payload na fila não tem o par', function () {
    [$processamento, $documento] = cenarioReenvioOcrJob();

    $parametros = array_map(
        fn (ReflectionParameter $parametro) => $parametro->getName(),
        (new ReflectionMethod(VerificarReenvioOcrJob::class, '__construct'))->getParameters(),
    );

    dispatch((new VerificarReenvioOcrJob($processamento->id, $documento->id))->onConnection('database'));
    $linha = DB::table('jobs')->where('queue', 'ocr-envio')->sole();

    expect($parametros)->toBe(['processamentoId', 'documentoId'])
        ->and($linha->payload)->not->toContain(LOGIN_REENVIO_OCR_JOB)
        ->and($linha->payload)->not->toContain(SENHA_REENVIO_OCR_JOB)
        ->and(json_decode($linha->payload, true)['data']['commandName'])->toBe(VerificarReenvioOcrJob::class)
        ->and(json_decode($linha->payload, true)['maxTries'])->toBe(1);
});

it('uma consulta por documento enquanto a anterior não termina; outro documento tem a sua', function () {
    Queue::fake([VerificarReenvioOcrJob::class, EnviarAvisoOcrJob::class]);
    [$processamento, $documento] = cenarioReenvioOcrJob();
    [$outroProcessamento, $outro] = cenarioReenvioOcrJob();

    verificarReenvioOcrJob($processamento, $documento);
    verificarReenvioOcrJob($processamento, $documento);
    verificarReenvioOcrJob($outroProcessamento, $outro);

    Queue::assertPushedTimes(VerificarReenvioOcrJob::class, 2);
    Queue::assertPushedOn('ocr-envio', VerificarReenvioOcrJob::class);
});

// ---------- reenvio (AC c, p, o) ----------

it('failed: GET, DELETE e POST do documento, nessa ordem, com 0 SOAP; depois o webhook do job antigo recebe 404 com 0 avisos (AC c)', function () {
    [$processamento, $documento, $processo] = cenarioReenvioOcrJob();
    simularWorkflowReenvioOcrJob('failed');

    verificarReenvioOcrJob($processamento, $documento);

    expect(chamadasReenvioOcrJob())->toBe([GET_ANTIGO_REENVIO_OCR_JOB, DELETE_ANTIGO_REENVIO_OCR_JOB, POST_REENVIO_OCR_JOB])
        ->and($this->mni->requisicoes)->toBe([]);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request['filename'] === "{$documento->id_documento}.pdf"
        && $request->hasHeader('Authorization', 'Bearer '.TOKEN_WORKFLOW_REENVIO_OCR_JOB));

    $documento->refresh();

    expect($documento->ocr_situacao)->toBe(ProcessoDocumento::OCR_ENVIADO)
        ->and($documento->ocr_job_id)->toBe(JOB_NOVO_REENVIO_OCR_JOB)
        ->and($documento->ocr_job_id_cancelar)->toBeNull()
        ->and($documento->ocr_enviado_em->equalTo(now()))->toBeTrue()
        ->and($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO)
        ->and($processamento->fresh()->ultimo_progresso_em)->toBeNull();

    $contagemAntes = contagemReenvioOcrJob($processo);
    $avisosAntes = ProcessoOcrAviso::count();

    webhookReenvioOcrJob($documento, JOB_ANTIGO_REENVIO_OCR_JOB)->assertNotFound();
    webhookReenvioOcrJob($documento, JOB_ANTIGO_REENVIO_OCR_JOB, 'error')->assertNotFound();

    expect(contagemReenvioOcrJob($processo))->toEqual($contagemAntes)
        ->and(ProcessoOcrAviso::count())->toBe($avisosAntes)
        ->and($avisosAntes)->toBe(0)
        ->and($documento->fresh()->ocr_job_id)->toBe(JOB_NOVO_REENVIO_OCR_JOB);
});

it('404 e cancelled também levam a 1 DELETE e 1 POST (R6-1, R7-1, AC p)', function (string $status) {
    [$processamento, $documento] = cenarioReenvioOcrJob();
    simularWorkflowReenvioOcrJob($status);

    verificarReenvioOcrJob($processamento, $documento);

    expect(chamadasReenvioOcrJob())->toBe([GET_ANTIGO_REENVIO_OCR_JOB, DELETE_ANTIGO_REENVIO_OCR_JOB, POST_REENVIO_OCR_JOB])
        ->and($documento->fresh()->ocr_job_id)->toBe(JOB_NOVO_REENVIO_OCR_JOB)
        ->and($this->mni->requisicoes)->toBe([]);
})->with(['404', 'cancelled']);

it('running ou pending com idade ≥ M: 1 DELETE e 1 POST (AC o)', function (string $status, int $idade) {
    [$processamento, $documento] = cenarioReenvioOcrJob($idade);
    simularWorkflowReenvioOcrJob($status);

    verificarReenvioOcrJob($processamento, $documento);

    expect(chamadasReenvioOcrJob())->toBe([GET_ANTIGO_REENVIO_OCR_JOB, DELETE_ANTIGO_REENVIO_OCR_JOB, POST_REENVIO_OCR_JOB])
        ->and($documento->fresh()->ocr_job_id)->toBe(JOB_NOVO_REENVIO_OCR_JOB);
})->with(['running', 'pending'])->with([
    'idade M' => [60],
    'idade M + 5' => [65],
]);

it('com Reenviar, devolve o documento à fila e despacha o envio na fila ocr-envio', function () {
    Queue::fake([EnviarDocumentoAoOcrJob::class, EnviarAvisoOcrJob::class]);
    [$processamento, $documento] = cenarioReenvioOcrJob();
    simularWorkflowReenvioOcrJob('failed');

    verificarReenvioOcrJob($processamento, $documento);

    Queue::assertPushedOn('ocr-envio', EnviarDocumentoAoOcrJob::class, fn (EnviarDocumentoAoOcrJob $job) => $job->processamentoId === $processamento->id
        && $job->documentoId === $documento->id);

    $documento->refresh();

    // Devolvido (D1): o job antigo deixa de ser conhecido desde já (RF-12).
    expect($documento->ocr_situacao)->toBe(ProcessoDocumento::OCR_PENDENTE)
        ->and($documento->ocr_job_id)->toBeNull()
        ->and($documento->ocr_job_id_cancelar)->toBe(JOB_ANTIGO_REENVIO_OCR_JOB)
        ->and(chamadasReenvioOcrJob())->toBe([GET_ANTIGO_REENVIO_OCR_JOB]);

    webhookReenvioOcrJob($documento, JOB_ANTIGO_REENVIO_OCR_JOB)->assertNotFound();
});

it('404 no GET e DELETE 500: 0 POST, e o documento termina em erro no failed() do envio (AC d)', function () {
    [$processamento, $documento, $processo] = cenarioReenvioOcrJob();
    simularWorkflowReenvioOcrJob('404', delete: fn () => Http::response(['message' => 'erro'], 500));

    // Fila `sync`: sem nova tentativa, a falha do DELETE chama o failed()
    // do envio e sobe até aqui.
    expect(fn () => verificarReenvioOcrJob($processamento, $documento))
        ->toThrow(FalhaTransitoriaWorkflowOcrException::class);

    $documento->refresh();

    expect(chamadasReenvioOcrJob())->toBe([GET_ANTIGO_REENVIO_OCR_JOB, DELETE_ANTIGO_REENVIO_OCR_JOB])
        ->and($documento->ocr_situacao)->toBe(ProcessoDocumento::OCR_ERRO)
        ->and($documento->ocr_erro)->toContain('DELETE jobs/{job_id}/chunks')
        ->and($documento->ocr_job_id_cancelar)->toBe(JOB_ANTIGO_REENVIO_OCR_JOB)
        ->and(contagemReenvioOcrJob($processo)->comErro)->toBe(1);
});

// ---------- sem reenvio (AC l, m, n) ----------

it('running ou pending com idade < M: 0 DELETE e 0 POST, e o documento segue enviado com o job conhecido (AC l)', function (string $status, int $idade) {
    [$processamento, $documento] = cenarioReenvioOcrJob($idade);
    simularWorkflowReenvioOcrJob($status);
    $antes = estadoReenvioOcrJob($documento);

    verificarReenvioOcrJob($processamento, $documento);

    expect(chamadasReenvioOcrJob())->toBe([GET_ANTIGO_REENVIO_OCR_JOB])
        ->and(estadoReenvioOcrJob($documento))->toBe($antes)
        ->and($documento->fresh()->ocr_job_id)->toBe(JOB_ANTIGO_REENVIO_OCR_JOB);

    // O job segue conhecido: o retorno dele ainda vale.
    webhookReenvioOcrJob($documento, JOB_ANTIGO_REENVIO_OCR_JOB)->assertOk();

    expect($documento->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_PROCESSADO);
})->with(['running', 'pending'])->with([
    'idade A' => [20],
    'idade M - 1' => [59],
]);

it('running na rodada e 404 na seguinte: 1 GET, depois 1 GET, 1 DELETE e 1 POST (AC l)', function () {
    [$processamento, $documento] = cenarioReenvioOcrJob();
    $respostas = ['running', '404'];
    simularWorkflowReenvioOcrJob(function () use (&$respostas) {
        return array_shift($respostas) === '404'
            ? Http::response(['detail' => 'Job não encontrado'], 404)
            : Http::response(['id' => JOB_ANTIGO_REENVIO_OCR_JOB, 'status' => 'running'], 200);
    });

    verificarReenvioOcrJob($processamento, $documento);
    expect(chamadasReenvioOcrJob())->toBe([GET_ANTIGO_REENVIO_OCR_JOB]);

    $this->travel(1)->minutes();
    verificarReenvioOcrJob($processamento, $documento);

    expect(chamadasReenvioOcrJob())->toBe([
        GET_ANTIGO_REENVIO_OCR_JOB,
        GET_ANTIGO_REENVIO_OCR_JOB, DELETE_ANTIGO_REENVIO_OCR_JOB, POST_REENVIO_OCR_JOB,
    ]);
});

it('success: 0 DELETE e 0 POST, processados + 1, 1 aviso por assinante e ultimo_progresso_em atualizado (AC m)', function () {
    [$processamento, $documento, $processo] = cenarioReenvioOcrJob();
    simularWorkflowReenvioOcrJob('success');
    $antes = contagemReenvioOcrJob($processo);
    $this->travel(1)->minutes();

    verificarReenvioOcrJob($processamento, $documento);

    $processamento->refresh();
    $avisos = ProcessoOcrAviso::query()
        ->whereIn('assinante_id', $processamento->assinantes()->pluck('id'))
        ->get();

    expect(chamadasReenvioOcrJob())->toBe([GET_ANTIGO_REENVIO_OCR_JOB])
        ->and(contagemReenvioOcrJob($processo)->processados)->toBe($antes->processados + 1)
        ->and($documento->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_PROCESSADO)
        ->and($avisos)->toHaveCount(2)
        ->and($avisos->pluck('assinante_id')->unique())->toHaveCount(2)
        ->and($avisos->every(fn (ProcessoOcrAviso $aviso) => ! $aviso->final && $aviso->payload['situacao'] === 'em_andamento'))->toBeTrue()
        ->and($processamento->ultimo_progresso_em->equalTo(now()))->toBeTrue()
        ->and($processamento->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO);

    Queue::assertPushedOn('ocr-aviso', EnviarAvisoOcrJob::class);
});

it('503 ou tempo esgotado: 0 DELETE e 0 POST, sem exceção; a rodada seguinte consulta de novo (AC n)', function (Closure $resposta) {
    [$processamento, $documento] = cenarioReenvioOcrJob();
    simularWorkflowReenvioOcrJob($resposta);
    $antes = estadoReenvioOcrJob($documento);

    verificarReenvioOcrJob($processamento, $documento);

    expect(chamadasReenvioOcrJob())->toBe([GET_ANTIGO_REENVIO_OCR_JOB])
        ->and(estadoReenvioOcrJob($documento))->toBe($antes);

    $this->travel(1)->minutes();
    verificarReenvioOcrJob($processamento, $documento);

    expect(chamadasReenvioOcrJob())->toBe([GET_ANTIGO_REENVIO_OCR_JOB, GET_ANTIGO_REENVIO_OCR_JOB])
        ->and(estadoReenvioOcrJob($documento))->toBe($antes);
})->with([
    '503' => [fn () => Http::response('Service Unavailable', 503)],
    'tempo esgotado' => [fn (Request $request) => Http::failedConnection('cURL error 28: Operation timed out')($request)],
]);

// ---------- saídas sem consultar ----------

it('processamento encerrado ou inexistente: 0 requisições', function (?string $situacao) {
    [$processamento, $documento] = cenarioReenvioOcrJob();
    simularWorkflowReenvioOcrJob('failed');

    if ($situacao === null) {
        $processamento->delete();
    } else {
        $processamento->update(['situacao' => $situacao, 'finalizado_em' => now()]);
    }

    $antes = estadoReenvioOcrJob($documento);

    verificarReenvioOcrJob($processamento, $documento);

    Http::assertNothingSent();
    expect(estadoReenvioOcrJob($documento))->toBe($antes);
})->with([
    'falhou' => [ProcessoOcrProcessamento::SITUACAO_FALHOU],
    'concluido' => [ProcessoOcrProcessamento::SITUACAO_CONCLUIDO],
    'inexistente' => [null],
]);

it('documento fora de enviado: 0 requisições', function (?string $situacao) {
    [$processamento, $documento] = cenarioReenvioOcrJob(atributos: ['ocr_situacao' => $situacao]);
    simularWorkflowReenvioOcrJob('failed');
    $antes = estadoReenvioOcrJob($documento);

    verificarReenvioOcrJob($processamento, $documento);

    Http::assertNothingSent();
    expect(estadoReenvioOcrJob($documento))->toBe($antes);
})->with([
    'pendente (null)' => [null],
    'pendente' => [ProcessoDocumento::OCR_PENDENTE],
    'processado' => [ProcessoDocumento::OCR_PROCESSADO],
    'erro' => [ProcessoDocumento::OCR_ERRO],
]);

it('idade < A: 0 requisições; com idade A, a consulta sai', function () {
    [$processamento, $documento] = cenarioReenvioOcrJob(19);
    simularWorkflowReenvioOcrJob('running');

    verificarReenvioOcrJob($processamento, $documento);
    Http::assertNothingSent();

    $this->travel(1)->minutes();
    verificarReenvioOcrJob($processamento, $documento);

    expect(chamadasReenvioOcrJob())->toBe([GET_ANTIGO_REENVIO_OCR_JOB]);
});

it('a idade A vem da configuração', function () {
    config()->set('services.n8n_ocr.idade_maxima_sem_retorno_minutos', 10);
    [$processamento, $documento] = cenarioReenvioOcrJob(10);
    simularWorkflowReenvioOcrJob('running');

    verificarReenvioOcrJob($processamento, $documento);

    expect(chamadasReenvioOcrJob())->toBe([GET_ANTIGO_REENVIO_OCR_JOB]);
});

it('documento de outro processo: 0 requisições', function () {
    [$processamento] = cenarioReenvioOcrJob();
    [, $outro] = cenarioReenvioOcrJob();
    simularWorkflowReenvioOcrJob('failed');

    verificarReenvioOcrJob($processamento, $outro);

    Http::assertNothingSent();
});
