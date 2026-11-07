<?php

use App\Http\Middleware\ForcarRespostaJson;
use App\Http\Middleware\InjectCredenciaisPjePadrao;
use App\Http\Middleware\ValidarAssinaturaWebhookOcr;
use App\Http\Middleware\ValidateApiToken;
use App\Jobs\EnviarAvisoOcrJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\ProcessoOcrAssinante;
use App\Models\ProcessoOcrAviso;
use App\Models\ProcessoOcrProcessamento;
use App\Services\Ocr\ContagemOcr;
use App\Services\Ocr\ContagemOcrService;
use App\Services\Ocr\ProcessamentoOcrService;
use App\Services\Ocr\WebhookOcrUrl;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Yaml\Yaml;

/*
 * Webhook do workflow "Jurídico OCR (Gemini)" (T15; RF-12, CT-04, D-01):
 * a rota sem X-API-Token, a credencial do `webhook_url` (URL assinada por
 * documento, D4, com validade M) e 1 teste por caso de RF-12. O workflow
 * chama sem `Accept: application/json`, como o n8n: o 422 sai em JSON por
 * causa de ForcarRespostaJson.
 */

uses(DatabaseTransactions::class);

const BASE_WEBHOOK_OCR = 'http://host.docker.internal:8002';
const MENSAGEM_401_WEBHOOK_OCR = 'Credencial do webhook inválida';
const MENSAGEM_404_WEBHOOK_OCR = 'Job não encontrado';

beforeEach(function () {
    Queue::fake();
    $this->freezeSecond();

    config()->set('services.n8n_ocr.webhook_base_url', BASE_WEBHOOK_OCR);
    config()->set('services.n8n_ocr.idade_maxima_absoluta_minutos', 60);
});

/**
 * Documentos do processo, um por item: a `ocr_situacao` (string ou null) ou
 * os atributos do documento.
 *
 * @param  list<string|array<string, mixed>|null>  $documentos
 * @return array{0: Processo, 1: Collection<int, ProcessoDocumento>}
 */
function documentosWebhookOcr(array $documentos): array
{
    return criarDocumentosLote(
        array_map(fn ($documento) => is_array($documento) ? $documento : ['ocr_situacao' => $documento], $documentos),
    );
}

/**
 * Processamento em andamento com o dono do par e 1 carona.
 *
 * @return array{0: ProcessoOcrProcessamento, 1: list<ProcessoOcrAssinante>}
 */
function abrirProcessamentoWebhookOcr(Processo $processo): array
{
    $processamento = ProcessoOcrProcessamento::create(['processo_id' => $processo->id]);

    $assinantes = [];
    foreach (['tok-dono', 'tok-carona'] as $i => $token) {
        $assinantes[] = ProcessoOcrAssinante::create([
            'processamento_id' => $processamento->id,
            'dono' => $i === 0,
            'callback_url' => "https://sistema.example/mni/p/{$processamento->id}/progresso?assinante={$token}",
            'callback_token' => $token,
        ]);
    }

    return [$processamento, $assinantes];
}

function contagemWebhookOcr(Processo $processo): ContagemOcr
{
    return app(ContagemOcrService::class)->contar($processo->id);
}

/**
 * @return Collection<int, ProcessoOcrAviso>
 */
function avisosWebhookOcr(ProcessoOcrAssinante $assinante): Collection
{
    return ProcessoOcrAviso::where('assinante_id', $assinante->id)->orderBy('id')->get();
}

/**
 * O retorno como o n8n manda: corpo JSON, sem `Accept: application/json` e
 * sem X-API-Token.
 */
function retornoWebhookOcr(string $url, array $corpo): TestResponse
{
    return test()->postJson($url, $corpo, ['Accept' => '*/*']);
}

/** Corpo do nó "Avisar o sistema". */
function corpoSucessoWebhookOcr(string $jobId): array
{
    return [
        'status' => 'success',
        'message' => 'OCR + RAG processados com sucesso',
        'job_id' => $jobId,
        'bucket_destino' => null,
        'path_destino' => null,
        'indexed_chunks' => 12,
    ];
}

/** Corpo do nó "Avisar falha". */
function corpoErroWebhookOcr(string $jobId, string $detalhe = 'Gemini respondeu 500'): array
{
    return [
        'status' => 'error',
        'message' => 'Falha ao processar OCR+RAG',
        'job_id' => $jobId,
        'error_detail' => $detalhe,
        'error' => 'Erro interno',
    ];
}

/** A situação do documento no OCR, para conferir que nada mudou. */
function estadoOcrWebhookOcr(ProcessoDocumento $documento): array
{
    return Arr::only($documento->fresh()->getAttributes(), [
        'ocr_situacao', 'ocr_job_id', 'ocr_job_id_cancelar', 'ocr_enviado_em', 'ocr_concluido_em', 'ocr_erro',
    ]);
}

// ---------- rota e credencial (CT-04, D4) ----------

it('registra POST /api/ocr/webhook nomeada ocr.webhook, fora de X-API-Token e do par padrão', function () {
    $rota = Route::getRoutes()->getByName('ocr.webhook');

    expect($rota)->not->toBeNull()
        ->and($rota->uri())->toBe('api/ocr/webhook')
        ->and($rota->methods())->toContain('POST')
        ->and($rota->gatherMiddleware())->toContain(ForcarRespostaJson::class, ValidarAssinaturaWebhookOcr::class)
        ->and($rota->gatherMiddleware())->not->toContain(ValidateApiToken::class)
        ->and($rota->gatherMiddleware())->not->toContain(InjectCredenciaisPjePadrao::class);
});

it('aceita o retorno sem X-API-Token e ignora um X-API-Token inválido', function (array $headers) {
    [, $documentos] = documentosWebhookOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-sem-token'],
    ]);

    $this->postJson(WebhookOcrUrl::gerar($documentos[0]), corpoSucessoWebhookOcr('job-sem-token'), ['Accept' => '*/*'] + $headers)
        ->assertOk()
        ->assertExactJson(['message' => 'OK']);

    expect($documentos[0]->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_PROCESSADO);
})->with([
    'sem X-API-Token' => [[]],
    'X-API-Token inválido' => [['X-API-Token' => 'token-que-nao-existe']],
]);

it('gera o webhook_url com a base que o n8n alcança, a rota assinada do documento e validade M', function () {
    [, $documentos] = documentosWebhookOcr([ProcessoDocumento::OCR_PENDENTE]);
    $documento = $documentos[0];

    config()->set('services.n8n_ocr.webhook_base_url', BASE_WEBHOOK_OCR.'/');

    $url = WebhookOcrUrl::gerar($documento);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith(BASE_WEBHOOK_OCR."/api/ocr/webhook?documento={$documento->id}&expires=")
        ->and((int) $query['documento'])->toBe($documento->id)
        ->and((int) $query['expires'])->toBe(now()->addMinutes(60)->getTimestamp())
        ->and($query['signature'])->toMatch('/^[0-9a-f]{64}$/');

    config()->set('services.n8n_ocr.idade_maxima_absoluta_minutos', 30);
    parse_str((string) parse_url(WebhookOcrUrl::gerar($documento), PHP_URL_QUERY), $query);

    expect((int) $query['expires'])->toBe(now()->addMinutes(30)->getTimestamp());
});

it('não gera webhook_url sem a base configurada', function () {
    [, $documentos] = documentosWebhookOcr([ProcessoDocumento::OCR_PENDENTE]);

    config()->set('services.n8n_ocr.webhook_base_url', '');

    WebhookOcrUrl::gerar($documentos[0]);
})->throws(RuntimeException::class, 'webhook_base_url');

it('confere a assinatura pelo caminho e pela query, sem depender do host por onde o n8n chega', function () {
    [, $documentos] = documentosWebhookOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-host'],
    ]);

    $url = WebhookOcrUrl::gerar($documentos[0]);
    $outroHost = str_replace(BASE_WEBHOOK_OCR, 'https://ms-mni.example', $url);

    retornoWebhookOcr($outroHost, corpoSucessoWebhookOcr('job-host'))->assertOk();
});

it('aceita o webhook_url depois de A = 20 min e aos 59 min, e recusa com 401 aos 61 min', function (int $minutos, int $status) {
    [, $documentos] = documentosWebhookOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-validade'],
    ]);
    $antes = estadoOcrWebhookOcr($documentos[0]);

    $url = WebhookOcrUrl::gerar($documentos[0]);

    $this->travel($minutos)->minutes();

    $resposta = retornoWebhookOcr($url, corpoSucessoWebhookOcr('job-validade'))->assertStatus($status);

    if ($status === 401) {
        $resposta->assertExactJson(['message' => MENSAGEM_401_WEBHOOK_OCR]);

        expect(estadoOcrWebhookOcr($documentos[0]))->toBe($antes);

        return;
    }

    expect($documentos[0]->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_PROCESSADO);
})->with([
    'A = 20 min (RF-11 AC g)' => [20, 200],
    '59 min' => [59, 200],
    '61 min, depois de M' => [61, 401],
]);

it('recusa com 401 o webhook_url com o documento adulterado, sem mudar nenhum dos dois', function () {
    [, $documentos] = documentosWebhookOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-assinado'],
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-alvo'],
    ]);
    [$assinado, $alvo] = [$documentos[0], $documentos[1]];
    $antes = [estadoOcrWebhookOcr($assinado), estadoOcrWebhookOcr($alvo)];

    $adulterada = str_replace("documento={$assinado->id}&", "documento={$alvo->id}&", WebhookOcrUrl::gerar($assinado));

    expect($adulterada)->toContain("documento={$alvo->id}&");

    retornoWebhookOcr($adulterada, corpoSucessoWebhookOcr('job-alvo'))
        ->assertUnauthorized()
        ->assertExactJson(['message' => MENSAGEM_401_WEBHOOK_OCR]);

    expect([estadoOcrWebhookOcr($assinado), estadoOcrWebhookOcr($alvo)])->toBe($antes);
});

// ---------- RF-12: 1 teste por caso ----------

it('401: sem credencial válida responde 401 antes de validar o corpo, sem mudança', function (Closure $url) {
    [$processo, $documentos] = documentosWebhookOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-401'],
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-401-b'],
    ]);
    abrirProcessamentoWebhookOcr($processo);
    $antes = estadoOcrWebhookOcr($documentos[0]);

    retornoWebhookOcr($url($documentos[0]), corpoSucessoWebhookOcr('job-401'))
        ->assertUnauthorized()
        ->assertExactJson(['message' => MENSAGEM_401_WEBHOOK_OCR]);

    expect(estadoOcrWebhookOcr($documentos[0]))->toBe($antes)
        ->and(ProcessoOcrAviso::count())->toBe(0);

    Queue::assertNothingPushed();
})->with([
    'sem assinatura' => fn (ProcessoDocumento $documento) => "/api/ocr/webhook?documento={$documento->id}",
    'sem query' => fn (ProcessoDocumento $documento) => '/api/ocr/webhook',
    'assinatura errada' => fn (ProcessoDocumento $documento) => preg_replace(
        '/signature=[0-9a-f]+/',
        'signature='.str_repeat('0', 64),
        WebhookOcrUrl::gerar($documento),
    ),
    'expires adulterado' => fn (ProcessoDocumento $documento) => preg_replace_callback(
        '/expires=(\d+)/',
        fn (array $m) => 'expires='.((int) $m[1] + 3600),
        WebhookOcrUrl::gerar($documento),
    ),
]);

it('422 com credencial e 401 sem credencial para corpo sem job_id ou com status fora de success e error (AC c)', function (Closure $corpo, string $campo) {
    $jobId = (string) Str::uuid();
    [, $documentos] = documentosWebhookOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => $jobId],
    ]);
    $documento = $documentos[0];
    $antes = estadoOcrWebhookOcr($documento);

    retornoWebhookOcr(WebhookOcrUrl::gerar($documento), $corpo($jobId))
        ->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonStructure(['message', 'errors' => [$campo]]);

    retornoWebhookOcr("/api/ocr/webhook?documento={$documento->id}", $corpo($jobId))
        ->assertUnauthorized()
        ->assertExactJson(['message' => MENSAGEM_401_WEBHOOK_OCR]);

    expect(estadoOcrWebhookOcr($documento))->toBe($antes)
        ->and(ProcessoOcrAviso::count())->toBe(0);
})->with([
    'sem job_id' => [fn (string $jobId) => ['status' => 'success'], 'job_id'],
    'status fora do enum' => [fn (string $jobId) => ['status' => 'outro', 'job_id' => $jobId], 'status'],
]);

it('422: job_id que não é texto ou maior que 64 e status ausente, sem mudança', function (array $corpo, string $campo) {
    [, $documentos] = documentosWebhookOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-422'],
    ]);
    $antes = estadoOcrWebhookOcr($documentos[0]);

    retornoWebhookOcr(WebhookOcrUrl::gerar($documentos[0]), $corpo)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$campo]);

    expect(estadoOcrWebhookOcr($documentos[0]))->toBe($antes);
})->with([
    'job_id numérico' => [['status' => 'success', 'job_id' => 123], 'job_id'],
    'job_id com 65 caracteres' => [['status' => 'success', 'job_id' => str_repeat('a', 65)], 'job_id'],
    'sem status' => [['job_id' => 'job-422'], 'status'],
    'corpo vazio' => [[], 'job_id'],
]);

it('404: job_id desconhecido do documento responde 404, sem mudança e com 0 avisos', function (string $jobId) {
    [$processo, $documentos] = documentosWebhookOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-atual'],
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-do-outro-documento'],
    ]);
    abrirProcessamentoWebhookOcr($processo);
    $antes = [estadoOcrWebhookOcr($documentos[0]), estadoOcrWebhookOcr($documentos[1])];
    $contagemAntes = contagemWebhookOcr($processo);

    foreach ([corpoSucessoWebhookOcr($jobId), corpoErroWebhookOcr($jobId)] as $corpo) {
        retornoWebhookOcr(WebhookOcrUrl::gerar($documentos[0]), $corpo)
            ->assertNotFound()
            ->assertExactJson(['message' => MENSAGEM_404_WEBHOOK_OCR]);
    }

    expect([estadoOcrWebhookOcr($documentos[0]), estadoOcrWebhookOcr($documentos[1])])->toBe($antes)
        ->and(contagemWebhookOcr($processo))->toEqual($contagemAntes)
        ->and(ProcessoOcrAviso::count())->toBe(0);

    Queue::assertNothingPushed();
})->with([
    'job nunca enviado' => [(string) Str::uuid()],
    'job de outro documento, com a credencial deste' => ['job-do-outro-documento'],
]);

it('success: o documento aguardando retorno vira processado, responde 200 e avisa cada assinante', function () {
    [$processo, $documentos] = documentosWebhookOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-1'],
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-2'],
    ]);
    [$processamento, $assinantes] = abrirProcessamentoWebhookOcr($processo);

    $this->travel(3)->minutes();

    retornoWebhookOcr(WebhookOcrUrl::gerar($documentos[0]), corpoSucessoWebhookOcr('job-1'))
        ->assertOk()
        ->assertExactJson(['message' => 'OK']);

    $documento = $documentos[0]->fresh();

    expect($documento->ocr_situacao)->toBe(ProcessoDocumento::OCR_PROCESSADO)
        ->and($documento->ocr_concluido_em->equalTo(now()))->toBeTrue()
        ->and($documento->ocr_job_id)->toBe('job-1')
        ->and($processamento->fresh()->ultimo_progresso_em->equalTo(now()))->toBeTrue()
        ->and($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO);

    foreach ($assinantes as $assinante) {
        expect(avisosWebhookOcr($assinante)->sole()->payload)->toMatchArray([
            'total' => 2,
            'processados' => 1,
            'com_erro' => 0,
            'situacao' => 'em_andamento',
        ]);
    }

    Queue::assertPushed(EnviarAvisoOcrJob::class, 2);
});

it('error: o documento aguardando retorno vira com_erro com o detalhe, responde 200 e avisa cada assinante', function () {
    [$processo, $documentos] = documentosWebhookOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-1'],
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-2'],
    ]);
    [, $assinantes] = abrirProcessamentoWebhookOcr($processo);

    retornoWebhookOcr(WebhookOcrUrl::gerar($documentos[0]), corpoErroWebhookOcr('job-1', 'Gemini respondeu 500'))
        ->assertOk()
        ->assertExactJson(['message' => 'OK']);

    $documento = $documentos[0]->fresh();

    expect($documento->ocr_situacao)->toBe(ProcessoDocumento::OCR_ERRO)
        ->and($documento->ocr_erro)->toBe('Gemini respondeu 500')
        ->and($documento->ocr_job_id)->toBe('job-1')
        ->and(contagemWebhookOcr($processo)->comErro)->toBe(1);

    foreach ($assinantes as $assinante) {
        expect(avisosWebhookOcr($assinante)->sole()->payload)->toMatchArray([
            'total' => 2,
            'processados' => 0,
            'com_erro' => 1,
            'situacao' => 'em_andamento',
        ]);
    }
});

it('success sobre erro: processados + 1 e com_erro − 1, com 1 aviso por assinante só com processamento aberto (AC a)', function (bool $aberto) {
    [$processo, $documentos] = documentosWebhookOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_ERRO, 'ocr_job_id' => 'job-a', 'ocr_erro' => 'job não encontrado'],
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-b'],
    ]);
    [$processamento, $assinantes] = abrirProcessamentoWebhookOcr($processo);

    if (! $aberto) {
        $processamento->update(['situacao' => ProcessoOcrProcessamento::SITUACAO_FALHOU, 'finalizado_em' => now()]);
    }

    $antes = contagemWebhookOcr($processo);

    retornoWebhookOcr(WebhookOcrUrl::gerar($documentos[0]), corpoSucessoWebhookOcr('job-a'))
        ->assertOk()
        ->assertExactJson(['message' => 'OK']);

    $depois = contagemWebhookOcr($processo);

    expect($depois->processados)->toBe($antes->processados + 1)
        ->and($depois->comErro)->toBe($antes->comErro - 1)
        ->and($documentos[0]->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_PROCESSADO)
        ->and($documentos[0]->fresh()->ocr_erro)->toBeNull();

    foreach ($assinantes as $assinante) {
        $avisos = avisosWebhookOcr($assinante);

        expect($avisos)->toHaveCount($aberto ? 1 : 0);

        if ($aberto) {
            expect($avisos[0]->payload)->toMatchArray([
                'processados' => 1,
                'com_erro' => 0,
                'situacao' => 'em_andamento',
            ]);
        }
    }
})->with([
    'com processamento aberto' => [true],
    'sem processamento aberto' => [false],
]);

it('error sobre processado: 200, sem mudança nas contagens e com 0 avisos (AC b)', function () {
    [$processo, $documentos] = documentosWebhookOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_PROCESSADO, 'ocr_job_id' => 'job-lido', 'ocr_concluido_em' => now()->subMinute()],
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-2'],
    ]);
    abrirProcessamentoWebhookOcr($processo);
    $antes = estadoOcrWebhookOcr($documentos[0]);
    $contagemAntes = contagemWebhookOcr($processo);

    retornoWebhookOcr(WebhookOcrUrl::gerar($documentos[0]), corpoErroWebhookOcr('job-lido'))
        ->assertOk()
        ->assertExactJson(['message' => 'OK']);

    expect(estadoOcrWebhookOcr($documentos[0]))->toBe($antes)
        ->and(contagemWebhookOcr($processo))->toEqual($contagemAntes)
        ->and(ProcessoOcrAviso::count())->toBe(0);

    Queue::assertNothingPushed();
});

it('repetido: o mesmo retorno de novo responde 200, sem mudança e com 0 avisos', function (Closure $corpo, string $situacao) {
    [$processo, $documentos] = documentosWebhookOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-rep'],
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-2'],
    ]);
    abrirProcessamentoWebhookOcr($processo);
    $url = WebhookOcrUrl::gerar($documentos[0]);

    retornoWebhookOcr($url, $corpo('job-rep'))->assertOk();

    $depoisDoPrimeiro = estadoOcrWebhookOcr($documentos[0]);
    $contagem = contagemWebhookOcr($processo);
    $avisos = ProcessoOcrAviso::count();

    expect($depoisDoPrimeiro['ocr_situacao'])->toBe($situacao)
        ->and($avisos)->toBe(2);

    $this->travel(1)->minutes();

    retornoWebhookOcr($url, $corpo('job-rep'))
        ->assertOk()
        ->assertExactJson(['message' => 'OK']);

    expect(estadoOcrWebhookOcr($documentos[0]))->toBe($depoisDoPrimeiro)
        ->and(contagemWebhookOcr($processo))->toEqual($contagem)
        ->and(ProcessoOcrAviso::count())->toBe($avisos);
})->with([
    'success repetido' => [fn (string $jobId) => corpoSucessoWebhookOcr($jobId), ProcessoDocumento::OCR_PROCESSADO],
    'error repetido' => [fn (string $jobId) => corpoErroWebhookOcr($jobId), ProcessoDocumento::OCR_ERRO],
]);

it('documento devolvido à fila: o job antigo recebe 404 antes e depois do reenvio, e só o job novo muda o documento (AC d)', function () {
    [$processo, $documentos] = documentosWebhookOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_ERRO, 'ocr_job_id' => 'job-antigo', 'ocr_erro' => 'falha anterior'],
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-2'],
    ]);
    $documento = $documentos[0];
    $urlAntiga = WebhookOcrUrl::gerar($documento);

    // Reabertura: o com_erro volta a pendente (RF-04, NC-3).
    abrirProcessamentoWebhookOcr($processo);
    expect(app(ProcessamentoOcrService::class)->devolverParaFila($documento))->toBeTrue();

    $antes = estadoOcrWebhookOcr($documento);
    $contagemAntes = contagemWebhookOcr($processo);

    foreach ([corpoSucessoWebhookOcr('job-antigo'), corpoErroWebhookOcr('job-antigo')] as $corpo) {
        retornoWebhookOcr($urlAntiga, $corpo)
            ->assertNotFound()
            ->assertExactJson(['message' => MENSAGEM_404_WEBHOOK_OCR]);
    }

    expect(estadoOcrWebhookOcr($documento))->toBe($antes)
        ->and(contagemWebhookOcr($processo))->toEqual($contagemAntes)
        ->and(ProcessoOcrAviso::count())->toBe(0);

    // Reenvio: o workflow aceita o documento com outro job (RF-11).
    expect(app(ProcessamentoOcrService::class)->registrarEnvio($documento->fresh(), 'job-novo'))->toBeTrue();

    $urlNova = WebhookOcrUrl::gerar($documento);
    $depoisDoReenvio = estadoOcrWebhookOcr($documento);

    foreach ([$urlAntiga, $urlNova] as $url) {
        retornoWebhookOcr($url, corpoSucessoWebhookOcr('job-antigo'))->assertNotFound();
        retornoWebhookOcr($url, corpoErroWebhookOcr('job-antigo'))->assertNotFound();
    }

    expect(estadoOcrWebhookOcr($documento))->toBe($depoisDoReenvio)
        ->and(ProcessoOcrAviso::count())->toBe(0);

    retornoWebhookOcr($urlNova, corpoSucessoWebhookOcr('job-novo'))->assertOk();

    expect($documento->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_PROCESSADO)
        ->and(ProcessoOcrAviso::count())->toBe(2);
});

it('não registra o corpo do retorno no log', function () {
    [$processo, $documentos] = documentosWebhookOcr([
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-log'],
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-2'],
    ]);
    abrirProcessamentoWebhookOcr($processo);

    $logs = [];
    Event::listen(MessageLogged::class, function (MessageLogged $evento) use (&$logs) {
        $logs[] = $evento->message.' '.json_encode($evento->context);
    });

    $corpo = corpoErroWebhookOcr('job-log', 'detalhe-marcador-do-corpo');
    $corpo['message'] = 'mensagem-marcador-do-corpo';
    $corpo['error'] = 'erro-marcador-do-corpo';

    retornoWebhookOcr(WebhookOcrUrl::gerar($documentos[0]), $corpo)->assertOk();

    expect($logs)->not->toBeEmpty()
        ->and(implode("\n", $logs))->toContain("[ProcessoDocumento:{$documentos[0]->id}] retorno do workflow")
        ->and(implode("\n", $logs))->not->toContain('marcador-do-corpo');
});

// ---------- contrato publicado (D-01) ----------

it('documenta POST /ocr/webhook no openapi.yaml oficial com security vazio e as respostas 200, 401, 404 e 422', function () {
    $contrato = Yaml::parseFile(base_path('docs/api/openapi.yaml'));
    $operacao = $contrato['paths']['/ocr/webhook']['post'] ?? null;

    expect($operacao)->not->toBeNull()
        ->and($operacao['security'])->toBe([])
        ->and($operacao['x-dependencia'])->toBe('DEP-04')
        ->and(array_keys($operacao['responses']))->toEqual(['200', '401', '404', '422'])
        ->and($operacao['requestBody']['content']['application/json']['schema'])
        ->toBe(['$ref' => '#/components/schemas/RetornoDoWorkflowOcr'])
        ->and($operacao['responses']['401']['content']['application/json']['schema'])
        ->toBe(['$ref' => '#/components/schemas/ErroMensagem'])
        ->and($operacao['responses']['404']['content']['application/json']['schema'])
        ->toBe(['$ref' => '#/components/schemas/ErroMensagem'])
        ->and($operacao['responses']['422'])->toBe(['$ref' => '#/components/responses/Erro422']);

    $schemas = $contrato['components']['schemas'];

    expect($schemas['RetornoDoWorkflowOcr']['oneOf'])->toBe([
        ['$ref' => '#/components/schemas/RetornoDoWorkflowOcrSucesso'],
        ['$ref' => '#/components/schemas/RetornoDoWorkflowOcrFalha'],
    ])
        ->and($schemas['RetornoDoWorkflowOcrSucesso']['required'])->toBe(['status', 'job_id'])
        ->and($schemas['RetornoDoWorkflowOcrSucesso']['properties']['status'])->toBe(['type' => 'string', 'const' => 'success'])
        ->and($schemas['RetornoDoWorkflowOcrFalha']['required'])->toBe(['status', 'job_id'])
        ->and($schemas['RetornoDoWorkflowOcrFalha']['properties']['status'])->toBe(['type' => 'string', 'const' => 'error']);
});

it('resolve todo $ref do openapi.yaml oficial', function () {
    $contrato = Yaml::parseFile(base_path('docs/api/openapi.yaml'));

    preg_match_all("/\\\$ref: '#\\/([^']+)'/", file_get_contents(base_path('docs/api/openapi.yaml')), $refs);

    expect($refs[1])->not->toBeEmpty();

    foreach (array_unique($refs[1]) as $ref) {
        $no = $contrato;

        foreach (explode('/', $ref) as $chave) {
            expect($no)->toHaveKey($chave);
            $no = $no[$chave];
        }
    }
});
