<?php

use App\Exceptions\FalhaTransitoriaWorkflowOcrException;
use App\Jobs\EnviarAvisoOcrJob;
use App\Jobs\EnviarDocumentoAoOcrJob;
use App\Models\ProcessoDocumento;
use App\Models\ProcessoOcrAssinante;
use App\Models\ProcessoOcrAviso;
use App\Models\ProcessoOcrProcessamento;
use App\Services\Ocr\ContagemOcr;
use App\Services\Ocr\ContagemOcrService;
use App\Services\Ocr\ProcessamentoOcrService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Envio ao workflow do OCR (T16; RF-11, CT-03, CT-07, RF-18 item 3 no
 * envio, RF-09/D3). Os casos de tentativas (422 e 500 sempre) rodam pela
 * fila `database` com o worker real (`queue:work --once`), para que
 * `$tries` e `backoff()` valham como em produção. Os demais rodam o
 * handle() como uma tentativa do worker.
 */

uses(DatabaseTransactions::class);

const BASE_WORKFLOW_ENVIO_OCR_JOB = 'http://n8n.test/webhook/juridico-ocr';
const TOKEN_WORKFLOW_ENVIO_OCR_JOB = 'token-workflow-envio-5e2';
const BASE_WEBHOOK_ENVIO_OCR_JOB = 'http://host.docker.internal:8002';
const BASE_CONHECIMENTO_ENVIO_OCR_JOB = 'base-processos-envio';
const JOB_NOVO_ENVIO_OCR_JOB = '0c6b2f1e-7a4d-4e1b-9f3a-2d5c8e1b4a70';
const JOB_ANTIGO_ENVIO_OCR_JOB = '9e8d7c6b-5a49-4382-a1b0-c9d8e7f6a5b4';
const LOGIN_ENVIO_OCR_JOB = 'login-envio-3c7';
const SENHA_ENVIO_OCR_JOB = 'senha-envio-8d4';

beforeEach(function () {
    isolarAvisosOcrDoBancoDeDev();
    $this->freezeSecond();
    Storage::fake('s3');

    config()->set('app.timezone', 'UTC');
    config()->set('services.n8n_ocr.url', BASE_WORKFLOW_ENVIO_OCR_JOB);
    config()->set('services.n8n_ocr.token', TOKEN_WORKFLOW_ENVIO_OCR_JOB);
    config()->set('services.n8n_ocr.base_conhecimento', BASE_CONHECIMENTO_ENVIO_OCR_JOB);
    config()->set('services.n8n_ocr.webhook_base_url', BASE_WEBHOOK_ENVIO_OCR_JOB);
    config()->set('services.n8n_ocr.prazo_sem_progresso_minutos', 25);
    config()->set('services.n8n_ocr.idade_maxima_sem_retorno_minutos', 20);
    config()->set('services.n8n_ocr.idade_maxima_absoluta_minutos', 60);
    config()->set('services.n8n_ocr.timeout_http_segundos', 30);
    // O par existe no ambiente; o envio nunca o usa nem o guarda.
    definirCredenciaisPadrao(LOGIN_ENVIO_OCR_JOB, SENHA_ENVIO_OCR_JOB);

    // O envio não chama o MNI (RF-09, R2-1): toda chamada fica registrada.
    $this->mni = simularIntegracaoMni(fn () => throw new RuntimeException('o envio ao OCR não chama o MNI'));
});

/**
 * Documento `baixado` com o objeto no S3, num processo com processamento
 * em andamento.
 *
 * @return array{0: ProcessoOcrProcessamento, 1: ProcessoDocumento}
 */
function cenarioEnvioOcrJob(array $atributos = [], string $mimetype = 'application/pdf', string $extensaoNoS3 = 'pdf', bool $comAssinantes = true): array
{
    [$processo, $documentos] = criarDocumentosLote([array_merge(['mimetype' => $mimetype], $atributos)]);
    $documento = $documentos->first();

    $path = "documentos-processos/{$processo->numero_processo}/{$documento->id_documento}.{$extensaoNoS3}";
    Storage::disk('s3')->put($path, "conteudo-{$documento->id_documento}");
    $documento->update(array_merge(['status' => ProcessoDocumento::STATUS_BAIXADO, 'path' => $path], $atributos));

    $processamento = ProcessoOcrProcessamento::create(['processo_id' => $processo->id]);

    if ($comAssinantes) {
        foreach (['tok-dono', 'tok-carona'] as $i => $token) {
            ProcessoOcrAssinante::create([
                'processamento_id' => $processamento->id,
                'dono' => $i === 0,
                'callback_url' => "https://sistema.example/mni/p/{$processamento->id}/progresso?assinante={$token}",
                'callback_token' => $token,
            ]);
        }
    }

    return [$processamento, $documento->fresh()];
}

/**
 * Workflow simulado por método: POST (CT-03), DELETE (CT-07) e GET (CT-05).
 * Sem resposta dada, POST aceita com o job novo e DELETE responde 200.
 */
function simularWorkflowEnvioOcrJob(?Closure $post = null, ?Closure $delete = null): void
{
    $post ??= fn () => Http::response(['id' => JOB_NOVO_ENVIO_OCR_JOB, 'status' => 'pending', 'error' => null], 202);
    $delete ??= fn () => Http::response(['job_id' => JOB_ANTIGO_ENVIO_OCR_JOB, 'deleted_chunks' => 4], 200);

    Http::fake(function (Request $request) use ($post, $delete) {
        return match ($request->method()) {
            'POST' => $post($request),
            'DELETE' => $delete($request),
            default => Http::response(['detail' => 'não esperado'], 599),
        };
    });
}

/**
 * As chamadas ao workflow, na ordem: "MÉTODO caminho".
 *
 * @return list<string>
 */
function chamadasWorkflowEnvioOcrJob(): array
{
    return Http::recorded()
        ->map(fn (array $par) => $par[0]->method().' '.Str::after($par[0]->url(), BASE_WORKFLOW_ENVIO_OCR_JOB))
        ->values()
        ->all();
}

/**
 * Uma tentativa do worker: o handle() com as dependências do container.
 */
function rodarEnvioOcrJob(ProcessoOcrProcessamento $processamento, ProcessoDocumento $documento): EnviarDocumentoAoOcrJob
{
    $job = (new EnviarDocumentoAoOcrJob($processamento->id, $documento->id))->withFakeQueueInteractions();

    app()->call([$job, 'handle']);

    return $job;
}

function falhaEnvioOcrJob(ProcessoOcrProcessamento $processamento, ProcessoDocumento $documento): FalhaTransitoriaWorkflowOcrException
{
    try {
        rodarEnvioOcrJob($processamento, $documento);
    } catch (FalhaTransitoriaWorkflowOcrException $e) {
        return $e;
    }

    throw new RuntimeException('Esperava FalhaTransitoriaWorkflowOcrException');
}

function contagemEnvioOcrJob(ProcessoDocumento $documento): ContagemOcr
{
    return app(ContagemOcrService::class)->contar($documento->processo_id);
}

/** Despacha na fila `database`, como a fila do Horizon guardaria. */
function despacharNaFilaEnvioOcrJob(ProcessoOcrProcessamento $processamento, ProcessoDocumento $documento): object
{
    dispatch((new EnviarDocumentoAoOcrJob($processamento->id, $documento->id))->onConnection('database'));

    return DB::table('jobs')->where('queue', EnviarDocumentoAoOcrJob::FILA)->sole();
}

/** Uma passada do worker real na fila do envio. */
function workerEnvioOcrJob(): void
{
    Artisan::call('queue:work', [
        'connection' => 'database',
        '--queue' => EnviarDocumentoAoOcrJob::FILA,
        '--once' => true,
        '--sleep' => 0,
    ]);
}

function estadoOcrEnvioOcrJob(ProcessoDocumento $documento): array
{
    return Arr::only($documento->fresh()->getAttributes(), [
        'status', 'ocr_situacao', 'ocr_job_id', 'ocr_job_id_cancelar', 'ocr_enviado_em', 'ocr_concluido_em', 'ocr_erro',
    ]);
}

// ---------- declaração do job ----------

it('declara a fila ocr-envio, 5 tentativas, backoff [60, 120, 300, 600], timeout 60 e ShouldBeUnique por documento', function () {
    $job = new EnviarDocumentoAoOcrJob(11, 22);

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job)->not->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($job->queue)->toBe('ocr-envio')
        ->and($job->tries)->toBe(5)
        ->and($job->backoff())->toBe([60, 120, 300, 600])
        ->and($job->timeout)->toBe(60)
        ->and($job->uniqueId())->toBe('22')
        ->and($job->uniqueFor)->toBeGreaterThanOrEqual(60 + 120 + 300 + 600 + 5 * 60);
});

it('o job recebe só os ids, e o payload na fila não tem o par (RF-11 AC b)', function () {
    [$processamento, $documento] = cenarioEnvioOcrJob();

    $parametros = array_map(
        fn (ReflectionParameter $parametro) => $parametro->getName(),
        (new ReflectionMethod(EnviarDocumentoAoOcrJob::class, '__construct'))->getParameters(),
    );

    $linha = despacharNaFilaEnvioOcrJob($processamento, $documento);
    $payload = json_decode($linha->payload, true);

    expect($parametros)->toBe(['processamentoId', 'documentoId'])
        ->and($linha->payload)->not->toContain(LOGIN_ENVIO_OCR_JOB)
        ->and($linha->payload)->not->toContain(SENHA_ENVIO_OCR_JOB)
        ->and($payload['data']['commandName'])->toBe(EnviarDocumentoAoOcrJob::class)
        ->and($payload['maxTries'])->toBe(5)
        ->and($payload['backoff'])->toBe('60,120,300,600')
        ->and($payload['timeout'])->toBe(60);
});

// ---------- envio aceito (RF-11 AC a, d, e) ----------

it('PDF: 1 POST com Authorization Bearer e o corpo de 5 chaves; o documento passa a enviado, sem progresso, sem aviso e com 0 SOAP', function () {
    Queue::fake([EnviarAvisoOcrJob::class]);
    [$processamento, $documento] = cenarioEnvioOcrJob(['descricao' => 'Petição inicial', 'data_juntada' => '2026-01-05 10:00:00']);
    simularWorkflowEnvioOcrJob();

    rodarEnvioOcrJob($processamento, $documento)->assertNotFailed()->assertNotReleased();

    expect(chamadasWorkflowEnvioOcrJob())->toBe(['POST /documents/process']);

    Http::assertSent(function (Request $request) use ($documento) {
        $corpo = $request->data();

        return $request->url() === BASE_WORKFLOW_ENVIO_OCR_JOB.'/documents/process'
            && $request->hasHeader('Authorization', 'Bearer '.TOKEN_WORKFLOW_ENVIO_OCR_JOB)
            && array_keys($corpo) === ['url', 'filename', 'webhook_url', 'base_conhecimento', 'metadata']
            && $corpo['filename'] === "{$documento->id_documento}.pdf"
            && $corpo['base_conhecimento'] === BASE_CONHECIMENTO_ENVIO_OCR_JOB
            && ! array_key_exists('escritorio_id', $corpo['metadata'])
            && $corpo['metadata']['data_juntada'] === '2026-01-05T10:00:00+00:00'
            && $corpo['metadata']['nivel_sigilo'] === 0
            && ! str_contains(json_encode($corpo), LOGIN_ENVIO_OCR_JOB)
            && ! str_contains(json_encode($corpo), SENHA_ENVIO_OCR_JOB);
    });

    $documento->refresh();

    expect($documento->ocr_situacao)->toBe(ProcessoDocumento::OCR_ENVIADO)
        ->and($documento->ocr_job_id)->toBe(JOB_NOVO_ENVIO_OCR_JOB)
        ->and($documento->ocr_enviado_em->equalTo(now()))->toBeTrue()
        ->and($processamento->fresh()->ultimo_progresso_em)->toBeNull()
        ->and(ProcessoOcrAviso::count())->toBe(0)
        ->and($this->mni->requisicoes)->toBe([]);

    Queue::assertNothingPushed();
});

it('envia HTML pelo .pdf gravado e imagens pela extensão do mimetype, sem objeto novo no S3', function (string $mimetype, string $extensaoNoS3, string $extensaoDoFilename) {
    Queue::fake([EnviarAvisoOcrJob::class]);
    [$processamento, $documento] = cenarioEnvioOcrJob([], $mimetype, $extensaoNoS3);
    simularWorkflowEnvioOcrJob();
    $objetosAntes = Storage::disk('s3')->allFiles();

    rodarEnvioOcrJob($processamento, $documento);

    Http::assertSent(fn (Request $request) => $request['filename'] === "{$documento->id_documento}.{$extensaoDoFilename}"
        && str_contains($request['url'], $documento->path.'?')
        && $request['metadata']['mimetype'] === $mimetype);

    expect(Storage::disk('s3')->allFiles())->toBe($objetosAntes)
        ->and($documento->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_ENVIADO)
        ->and($this->mni->requisicoes)->toBe([]);
})->with([
    'text/html' => ['text/html', 'pdf', 'pdf'],
    'image/png' => ['image/png', 'png', 'png'],
    'image/webp' => ['image/webp', 'dat', 'webp'],
    'image/gif' => ['image/gif', 'dat', 'gif'],
]);

it('o link do S3 é gerado no envio com expiração de pelo menos 25 min (RF-11 AC g)', function () {
    Queue::fake([EnviarAvisoOcrJob::class]);
    [$processamento, $documento] = cenarioEnvioOcrJob();
    simularWorkflowEnvioOcrJob();
    $this->travel(3)->minutes();

    rodarEnvioOcrJob($processamento, $documento);

    // Storage::fake gera `{path}?expiration={timestamp}`.
    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);

        return (int) $query['expiration'] >= now()->addMinutes(25)->getTimestamp();
    });
});

it('o webhook_url enviado ainda é aceito com o relógio 20 min (A) adiante (RF-11 AC g)', function () {
    Queue::fake([EnviarAvisoOcrJob::class]);
    [$processamento, $documento] = cenarioEnvioOcrJob();
    simularWorkflowEnvioOcrJob();

    rodarEnvioOcrJob($processamento, $documento);

    $webhookUrl = Http::recorded()->first()[0]['webhook_url'];
    $this->travel(20)->minutes();

    $this->postJson($webhookUrl, ['status' => 'success', 'job_id' => JOB_NOVO_ENVIO_OCR_JOB], ['Accept' => '*/*'])
        ->assertOk();

    expect($documento->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_PROCESSADO);
});

// ---------- respostas do workflow (RF-11 AC c) ----------

it('422: 1 tentativa só, e com_erro cresce 1 na hora', function () {
    [$processamento, $documento] = cenarioEnvioOcrJob(comAssinantes: false);
    simularWorkflowEnvioOcrJob(post: fn () => Http::response(['detail' => 'filename sem extensão aceita'], 422));
    despacharNaFilaEnvioOcrJob($processamento, $documento);
    $falhasAntes = DB::table('failed_jobs')->count();

    workerEnvioOcrJob();
    $this->travel(1)->hours();
    workerEnvioOcrJob();

    $documento->refresh();

    expect(Http::recorded())->toHaveCount(1)
        ->and(DB::table('jobs')->where('queue', EnviarDocumentoAoOcrJob::FILA)->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe($falhasAntes)
        ->and($documento->ocr_situacao)->toBe(ProcessoDocumento::OCR_ERRO)
        ->and($documento->ocr_erro)->toContain('HTTP 422')
        ->and($documento->ocr_erro)->toContain('filename sem extensão aceita')
        ->and(contagemEnvioOcrJob($documento)->comErro)->toBe(1);
});

it('500 em todas: 5 tentativas com o backoff, e com_erro só cresce 1 no failed(), depois da última', function () {
    [$processamento, $documento] = cenarioEnvioOcrJob(comAssinantes: false);
    simularWorkflowEnvioOcrJob(post: fn () => Http::response(['message' => 'Error in workflow'], 500));
    despacharNaFilaEnvioOcrJob($processamento, $documento);
    $falhasAntes = DB::table('failed_jobs')->count();

    foreach ([60, 120, 300, 600] as $tentativa => $espera) {
        workerEnvioOcrJob();

        expect(Http::recorded())->toHaveCount($tentativa + 1)
            ->and($documento->fresh()->ocr_situacao)->toBeNull()
            ->and(contagemEnvioOcrJob($documento)->comErro)->toBe(0);

        // Antes do backoff, a nova tentativa ainda não está disponível.
        $this->travel($espera - 1)->seconds();
        workerEnvioOcrJob();
        expect(Http::recorded())->toHaveCount($tentativa + 1);

        $this->travel(1)->seconds();
    }

    workerEnvioOcrJob();

    $documento->refresh();

    expect(Http::recorded())->toHaveCount(5)
        ->and(DB::table('jobs')->where('queue', EnviarDocumentoAoOcrJob::FILA)->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe($falhasAntes + 1)
        ->and($documento->ocr_situacao)->toBe(ProcessoDocumento::OCR_ERRO)
        ->and($documento->ocr_erro)->toContain('esgotou as tentativas')
        ->and($documento->ocr_erro)->toContain('HTTP 500')
        ->and(contagemEnvioOcrJob($documento)->comErro)->toBe(1)
        ->and($this->mni->requisicoes)->toBe([]);
});

it('tempo esgotado ou falha de rede no POST relança para nova tentativa, sem mudar o documento', function () {
    [$processamento, $documento] = cenarioEnvioOcrJob();
    simularWorkflowEnvioOcrJob(post: fn (Request $request) => Http::failedConnection('cURL error 28: Operation timed out')($request));
    $antes = estadoOcrEnvioOcrJob($documento);

    falhaEnvioOcrJob($processamento, $documento);

    expect(estadoOcrEnvioOcrJob($documento))->toBe($antes);
});

it('com 202 sem id do job, relança para nova tentativa', function () {
    [$processamento, $documento] = cenarioEnvioOcrJob();
    simularWorkflowEnvioOcrJob(post: fn () => Http::response(['status' => 'pending'], 202));

    falhaEnvioOcrJob($processamento, $documento);

    expect($documento->fresh()->ocr_situacao)->toBeNull();
});

// ---------- cancelamento do job antigo (CT-07, R2-2) ----------

it('com ocr_job_id_cancelar: 1 DELETE do job antigo antes de 1 POST, e a coluna é limpa', function () {
    Queue::fake([EnviarAvisoOcrJob::class]);
    [$processamento, $documento] = cenarioEnvioOcrJob([
        'ocr_situacao' => ProcessoDocumento::OCR_PENDENTE,
        'ocr_job_id_cancelar' => JOB_ANTIGO_ENVIO_OCR_JOB,
    ]);
    simularWorkflowEnvioOcrJob();

    rodarEnvioOcrJob($processamento, $documento);

    expect(chamadasWorkflowEnvioOcrJob())->toBe([
        'DELETE /jobs/'.JOB_ANTIGO_ENVIO_OCR_JOB.'/chunks',
        'POST /documents/process',
    ]);

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->hasHeader('Authorization', 'Bearer '.TOKEN_WORKFLOW_ENVIO_OCR_JOB)
        && $request->body() === '');

    $documento->refresh();

    expect($documento->ocr_job_id_cancelar)->toBeNull()
        ->and($documento->ocr_job_id)->toBe(JOB_NOVO_ENVIO_OCR_JOB)
        ->and($documento->ocr_situacao)->toBe(ProcessoDocumento::OCR_ENVIADO)
        ->and($this->mni->requisicoes)->toBe([]);
});

it('com_erro devolvido pela reabertura com job_id: 1 DELETE antes do POST; sem job_id: 0 DELETE (RF-18 AC g)', function () {
    Queue::fake([EnviarAvisoOcrJob::class]);
    [$processamento, $comJob] = cenarioEnvioOcrJob(['ocr_situacao' => ProcessoDocumento::OCR_ERRO, 'ocr_job_id' => JOB_ANTIGO_ENVIO_OCR_JOB]);
    [, $semJobLista] = criarDocumentosLote([['ocr_situacao' => ProcessoDocumento::OCR_ERRO, 'ocr_erro' => 'HTTP 422 no envio']], $comJob->processo);
    $semJob = $semJobLista->first();
    $path = "documentos-processos/{$comJob->processo->numero_processo}/{$semJob->id_documento}.pdf";
    Storage::disk('s3')->put($path, 'conteudo');
    $semJob->update(['status' => ProcessoDocumento::STATUS_BAIXADO, 'path' => $path]);

    $servico = app(ProcessamentoOcrService::class);
    expect($servico->devolverParaFila($comJob))->toBeTrue()
        ->and($servico->devolverParaFila($semJob))->toBeTrue();

    simularWorkflowEnvioOcrJob();

    rodarEnvioOcrJob($processamento, $comJob);
    expect(chamadasWorkflowEnvioOcrJob())->toBe([
        'DELETE /jobs/'.JOB_ANTIGO_ENVIO_OCR_JOB.'/chunks',
        'POST /documents/process',
    ]);

    rodarEnvioOcrJob($processamento, $semJob);
    expect(chamadasWorkflowEnvioOcrJob())->toBe([
        'DELETE /jobs/'.JOB_ANTIGO_ENVIO_OCR_JOB.'/chunks',
        'POST /documents/process',
        'POST /documents/process',
    ]);
});

it('sem job antigo: 0 DELETE e 1 POST', function () {
    Queue::fake([EnviarAvisoOcrJob::class]);
    [$processamento, $documento] = cenarioEnvioOcrJob(['ocr_situacao' => ProcessoDocumento::OCR_PENDENTE]);
    simularWorkflowEnvioOcrJob();

    rodarEnvioOcrJob($processamento, $documento);

    expect(chamadasWorkflowEnvioOcrJob())->toBe(['POST /documents/process']);
});

it('DELETE com falha relança, faz 0 POST e mantém o job a cancelar para a nova tentativa', function (Closure $resposta) {
    [$processamento, $documento] = cenarioEnvioOcrJob([
        'ocr_situacao' => ProcessoDocumento::OCR_PENDENTE,
        'ocr_job_id_cancelar' => JOB_ANTIGO_ENVIO_OCR_JOB,
    ]);
    simularWorkflowEnvioOcrJob(delete: $resposta);

    falhaEnvioOcrJob($processamento, $documento);

    $documento->refresh();

    expect(chamadasWorkflowEnvioOcrJob())->toBe(['DELETE /jobs/'.JOB_ANTIGO_ENVIO_OCR_JOB.'/chunks'])
        ->and($documento->ocr_job_id_cancelar)->toBe(JOB_ANTIGO_ENVIO_OCR_JOB)
        ->and($documento->ocr_situacao)->toBe(ProcessoDocumento::OCR_PENDENTE);
})->with([
    '500' => [fn () => Http::response(['message' => 'erro'], 500)],
    '404' => [fn () => Http::response(['detail' => 'não encontrado'], 404)],
    'tempo esgotado' => [fn (Request $request) => Http::failedConnection('cURL error 28: Operation timed out')($request)],
]);

it('DELETE 500 em todas as tentativas: 0 POST e com_erro no failed()', function () {
    [$processamento, $documento] = cenarioEnvioOcrJob([
        'ocr_situacao' => ProcessoDocumento::OCR_PENDENTE,
        'ocr_job_id_cancelar' => JOB_ANTIGO_ENVIO_OCR_JOB,
    ], comAssinantes: false);
    simularWorkflowEnvioOcrJob(delete: fn () => Http::response(['message' => 'erro'], 500));
    despacharNaFilaEnvioOcrJob($processamento, $documento);

    foreach ([60, 120, 300, 600, 0] as $espera) {
        workerEnvioOcrJob();
        $this->travel($espera)->seconds();
    }

    $documento->refresh();

    expect(chamadasWorkflowEnvioOcrJob())->toBe(array_fill(0, 5, 'DELETE /jobs/'.JOB_ANTIGO_ENVIO_OCR_JOB.'/chunks'))
        ->and($documento->ocr_situacao)->toBe(ProcessoDocumento::OCR_ERRO)
        ->and($documento->ocr_erro)->toContain('DELETE jobs/{job_id}/chunks')
        ->and(contagemEnvioOcrJob($documento)->comErro)->toBe(1);
});

it('POST com falha depois do DELETE: a nova tentativa não cancela de novo', function () {
    Queue::fake([EnviarAvisoOcrJob::class]);
    [$processamento, $documento] = cenarioEnvioOcrJob([
        'ocr_situacao' => ProcessoDocumento::OCR_PENDENTE,
        'ocr_job_id_cancelar' => JOB_ANTIGO_ENVIO_OCR_JOB,
    ]);
    $respostasPost = [Http::response(['message' => 'erro'], 503), Http::response(['id' => JOB_NOVO_ENVIO_OCR_JOB, 'status' => 'pending', 'error' => null], 202)];
    simularWorkflowEnvioOcrJob(post: function () use (&$respostasPost) {
        return array_shift($respostasPost);
    });

    falhaEnvioOcrJob($processamento, $documento);
    expect($documento->fresh()->ocr_job_id_cancelar)->toBeNull();

    rodarEnvioOcrJob($processamento, $documento->fresh());

    expect(chamadasWorkflowEnvioOcrJob())->toBe([
        'DELETE /jobs/'.JOB_ANTIGO_ENVIO_OCR_JOB.'/chunks',
        'POST /documents/process',
        'POST /documents/process',
    ])->and($documento->fresh()->ocr_job_id)->toBe(JOB_NOVO_ENVIO_OCR_JOB);
});

// ---------- saídas sem chamar o workflow ----------

it('processamento encerrado ou inexistente: 0 requisições e o documento não muda', function (?string $situacao) {
    [$processamento, $documento] = cenarioEnvioOcrJob(['ocr_job_id_cancelar' => JOB_ANTIGO_ENVIO_OCR_JOB]);
    simularWorkflowEnvioOcrJob();

    if ($situacao === null) {
        $processamento->delete();
    } else {
        $processamento->update(['situacao' => $situacao, 'finalizado_em' => now()]);
    }

    $antes = estadoOcrEnvioOcrJob($documento);

    rodarEnvioOcrJob($processamento, $documento)->assertNotFailed();

    Http::assertNothingSent();
    expect(estadoOcrEnvioOcrJob($documento))->toBe($antes);
})->with([
    'falhou' => [ProcessoOcrProcessamento::SITUACAO_FALHOU],
    'concluido' => [ProcessoOcrProcessamento::SITUACAO_CONCLUIDO],
    'inexistente' => [null],
]);

it('documento processado ou já enviado: 0 requisições e nada muda', function (string $situacao) {
    [$processamento, $documento] = cenarioEnvioOcrJob(['ocr_situacao' => $situacao, 'ocr_job_id' => JOB_ANTIGO_ENVIO_OCR_JOB]);
    simularWorkflowEnvioOcrJob();
    $antes = estadoOcrEnvioOcrJob($documento);

    rodarEnvioOcrJob($processamento, $documento);

    Http::assertNothingSent();
    expect(estadoOcrEnvioOcrJob($documento))->toBe($antes);
})->with([
    'processado' => [ProcessoDocumento::OCR_PROCESSADO],
    'enviado' => [ProcessoDocumento::OCR_ENVIADO],
]);

it('documento de outro processo: 0 requisições', function () {
    [$processamento] = cenarioEnvioOcrJob();
    [, $outro] = cenarioEnvioOcrJob();
    simularWorkflowEnvioOcrJob();

    rodarEnvioOcrJob($processamento, $outro);

    Http::assertNothingSent();
    expect($outro->fresh()->ocr_situacao)->toBeNull();
});

it('sem o objeto no S3: com_erro no OCR e binário em erro, com 0 requisições e 0 SOAP (D3)', function (Closure $preparar) {
    Queue::fake([EnviarAvisoOcrJob::class]);
    [$processamento, $documento] = cenarioEnvioOcrJob();
    $preparar($documento);
    simularWorkflowEnvioOcrJob();

    rodarEnvioOcrJob($processamento, $documento->fresh())->assertNotFailed();

    $documento->refresh();

    Http::assertNothingSent();
    expect($documento->ocr_situacao)->toBe(ProcessoDocumento::OCR_ERRO)
        ->and($documento->ocr_erro)->toContain('ausente no S3')
        ->and($documento->status)->toBe(ProcessoDocumento::STATUS_ERRO)
        ->and(contagemEnvioOcrJob($documento)->comErro)->toBe(1)
        ->and($this->mni->requisicoes)->toBe([]);

    // Progresso do processamento aberto: 1 aviso por assinante. Único
    // documento do processo, o processamento termina `concluido` (RF-15).
    expect(ProcessoOcrAviso::where('final', false)->count())->toBe(2)
        ->and(ProcessoOcrAviso::where('final', true)->count())->toBe(2)
        ->and($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_CONCLUIDO);
})->with([
    'objeto apagado' => [fn (ProcessoDocumento $d) => Storage::disk('s3')->delete($d->path)],
    'sem path' => [fn (ProcessoDocumento $d) => $d->update(['path' => null])],
    'binário pendente' => [fn (ProcessoDocumento $d) => $d->update(['status' => ProcessoDocumento::STATUS_PENDENTE])],
]);

// ---------- failed() ----------

it('failed() marca com_erro do documento pendente, com a falha do workflow e sem segredo', function () {
    Queue::fake([EnviarAvisoOcrJob::class]);
    [$processamento, $documento] = cenarioEnvioOcrJob();

    (new EnviarDocumentoAoOcrJob($processamento->id, $documento->id))->failed(
        new FalhaTransitoriaWorkflowOcrException('Workflow do OCR: POST documents/process respondeu fora do contrato (HTTP 502)', 502),
    );

    $documento->refresh();

    expect($documento->ocr_situacao)->toBe(ProcessoDocumento::OCR_ERRO)
        ->and($documento->ocr_erro)->toContain('HTTP 502')
        ->and($documento->ocr_erro)->not->toContain(TOKEN_WORKFLOW_ENVIO_OCR_JOB);
});

it('failed() de outra exceção registra só a classe, sem a mensagem', function () {
    Queue::fake([EnviarAvisoOcrJob::class]);
    [$processamento, $documento] = cenarioEnvioOcrJob();

    (new EnviarDocumentoAoOcrJob($processamento->id, $documento->id))->failed(
        new RuntimeException('https://bucket.s3.amazonaws.com/x.pdf?X-Amz-Signature=segredo'),
    );

    expect($documento->fresh()->ocr_erro)->toContain('RuntimeException')
        ->and($documento->fresh()->ocr_erro)->not->toContain('X-Amz-Signature');
});

it('failed() não muda documento já enviado ou processado', function (string $situacao) {
    Queue::fake([EnviarAvisoOcrJob::class]);
    [$processamento, $documento] = cenarioEnvioOcrJob(['ocr_situacao' => $situacao, 'ocr_job_id' => JOB_NOVO_ENVIO_OCR_JOB]);
    $antes = estadoOcrEnvioOcrJob($documento);

    (new EnviarDocumentoAoOcrJob($processamento->id, $documento->id))->failed(new RuntimeException('falha'));

    expect(estadoOcrEnvioOcrJob($documento))->toBe($antes)
        ->and(ProcessoOcrAviso::count())->toBe(0);
})->with([
    'enviado' => [ProcessoDocumento::OCR_ENVIADO],
    'processado' => [ProcessoDocumento::OCR_PROCESSADO],
]);
