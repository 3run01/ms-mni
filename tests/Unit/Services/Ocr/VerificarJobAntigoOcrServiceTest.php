<?php

use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\ProcessoOcrAssinante;
use App\Models\ProcessoOcrAviso;
use App\Models\ProcessoOcrProcessamento;
use App\Services\Ocr\ContagemOcrService;
use App\Services\Ocr\DecisaoReenvioOcr;
use App\Services\Ocr\VerificarJobAntigoOcrService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * Consulta do job antigo antes do reenvio (T16; D9, RF-18 item 3, CT-05,
 * R5-1, R6-1 a R6-3, R7-1): `success` vira processado sem reenvio; 404,
 * `failed` e `cancelled` reenviam; `pending`/`running` só reenviam com
 * idade ≥ M; a consulta com falha aguarda a rodada seguinte.
 */

uses(DatabaseTransactions::class);

const BASE_WORKFLOW_VERIFICAR_JOB_OCR = 'http://n8n.test/webhook/juridico-ocr';
const TOKEN_WORKFLOW_VERIFICAR_JOB_OCR = 'token-workflow-verificar-1a8';
const JOB_ANTIGO_VERIFICAR_JOB_OCR = '3b1f7c2d-8e4a-4f6b-9c0d-1e2f3a4b5c6d';

beforeEach(function () {
    Queue::fake();
    $this->freezeSecond();

    config()->set('services.n8n_ocr.url', BASE_WORKFLOW_VERIFICAR_JOB_OCR);
    config()->set('services.n8n_ocr.token', TOKEN_WORKFLOW_VERIFICAR_JOB_OCR);
    config()->set('services.n8n_ocr.idade_maxima_sem_retorno_minutos', 20);
    config()->set('services.n8n_ocr.idade_maxima_absoluta_minutos', 60);

    $this->logs = [];
    Event::listen(MessageLogged::class, function (MessageLogged $log) {
        $this->logs[] = $log;
    });
});

/**
 * Documento aguardando retorno do job antigo, enviado há `$idade` minutos,
 * com um 2º documento pendente para que o processamento não termine com o
 * retorno. Com `$abrir`, há processamento em andamento com 2 assinantes.
 *
 * @return array{0: ?ProcessoOcrProcessamento, 1: ProcessoDocumento, 2: Processo}
 */
function documentoEnviadoVerificarJobOcr(int $idade = 25, bool $abrir = true, array $atributos = []): array
{
    [$processo, $documentos] = criarDocumentosLote([
        array_merge([
            'ocr_situacao' => ProcessoDocumento::OCR_ENVIADO,
            'ocr_job_id' => JOB_ANTIGO_VERIFICAR_JOB_OCR,
            'ocr_enviado_em' => now()->subMinutes($idade),
        ], $atributos),
        ['ocr_situacao' => null],
    ]);

    $processamento = null;

    if ($abrir) {
        $processamento = ProcessoOcrProcessamento::create(['processo_id' => $processo->id]);

        foreach (['tok-dono', 'tok-carona'] as $i => $token) {
            ProcessoOcrAssinante::create([
                'processamento_id' => $processamento->id,
                'dono' => $i === 0,
                'callback_url' => "https://sistema.example/mni/p/{$processamento->id}/progresso?assinante={$token}",
                'callback_token' => $token,
            ]);
        }
    }

    return [$processamento, $documentos->first()->fresh(), $processo];
}

/**
 * `GET jobs/{job_id}` simulado: com `'404'`, o nó "Job nao encontrado"; com
 * outro texto, o `status` do nó "Responder job"; ou a resposta dada.
 */
function simularConsultaVerificarJobOcr(string|Closure $resposta): void
{
    Http::fake(function (Request $request) use ($resposta) {
        if ($resposta instanceof Closure) {
            return $resposta($request);
        }

        if ($resposta === '404') {
            return Http::response(['detail' => 'Job não encontrado'], 404);
        }

        return Http::response([
            'id' => JOB_ANTIGO_VERIFICAR_JOB_OCR,
            'status' => $resposta,
            'filename' => '930001.pdf',
            'progress' => 40,
            'progress_message' => 'Indexando',
            'error' => $resposta === 'failed' ? 'Gemini respondeu 500' : null,
            'created_at' => '2026-11-06T10:00:00Z',
            'updated_at' => '2026-11-06T10:01:00Z',
        ], 200);
    });
}

function verificarJobOcr(ProcessoDocumento $documento): DecisaoReenvioOcr
{
    return app(VerificarJobAntigoOcrService::class)->verificar($documento);
}

function estadoVerificarJobOcr(ProcessoDocumento $documento): array
{
    return Arr::only($documento->fresh()->getAttributes(), [
        'ocr_situacao', 'ocr_job_id', 'ocr_job_id_cancelar', 'ocr_enviado_em', 'ocr_concluido_em', 'ocr_erro',
    ]);
}

it('consulta GET jobs/{job antigo} com Authorization Bearer, uma vez só', function () {
    [, $documento] = documentoEnviadoVerificarJobOcr();
    simularConsultaVerificarJobOcr('running');

    verificarJobOcr($documento);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === BASE_WORKFLOW_VERIFICAR_JOB_OCR.'/jobs/'.JOB_ANTIGO_VERIFICAR_JOB_OCR
        && $request->hasHeader('Authorization', 'Bearer '.TOKEN_WORKFLOW_VERIFICAR_JOB_OCR));
});

it('success: Processado, sem reenvio; o documento passa a processado como progresso, com 1 aviso por assinante (R6-1)', function () {
    [$processamento, $documento, $processo] = documentoEnviadoVerificarJobOcr();
    simularConsultaVerificarJobOcr('success');
    $this->travel(2)->minutes();

    expect(verificarJobOcr($documento))->toBe(DecisaoReenvioOcr::Processado);

    $documento->refresh();
    $contagem = app(ContagemOcrService::class)->contar($processo->id);

    expect($documento->ocr_situacao)->toBe(ProcessoDocumento::OCR_PROCESSADO)
        ->and($documento->ocr_job_id)->toBe(JOB_ANTIGO_VERIFICAR_JOB_OCR)
        ->and($documento->ocr_job_id_cancelar)->toBeNull()
        ->and($contagem->processados)->toBe(1)
        ->and($processamento->fresh()->ultimo_progresso_em->equalTo(now()))->toBeTrue()
        ->and(ProcessoOcrAviso::where('final', false)->count())->toBe(2)
        ->and($processamento->fresh()->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO);

    // Só a consulta: nenhum DELETE nem POST.
    Http::assertSentCount(1);
});

it('success sem processamento aberto: processado, com 0 avisos', function () {
    [, $documento] = documentoEnviadoVerificarJobOcr(abrir: false);
    simularConsultaVerificarJobOcr('success');

    expect(verificarJobOcr($documento))->toBe(DecisaoReenvioOcr::Processado)
        ->and($documento->fresh()->ocr_situacao)->toBe(ProcessoDocumento::OCR_PROCESSADO)
        ->and(ProcessoOcrAviso::count())->toBe(0);
});

it('success depois do webhook do mesmo job: Processado, sem mudança e com 0 avisos', function () {
    [, $documento] = documentoEnviadoVerificarJobOcr();
    // O webhook chegou entre a leitura do documento e a consulta.
    ProcessoDocumento::whereKey($documento->id)->update([
        'ocr_situacao' => ProcessoDocumento::OCR_PROCESSADO,
        'ocr_concluido_em' => now(),
    ]);
    simularConsultaVerificarJobOcr('success');

    expect(verificarJobOcr($documento))->toBe(DecisaoReenvioOcr::Processado)
        ->and(ProcessoOcrAviso::count())->toBe(0);
});

it('404, failed ou cancelled: Reenviar, sem marcar erro nem mudar o documento (R6-1, R7-1)', function (string $resposta) {
    [, $documento] = documentoEnviadoVerificarJobOcr();
    simularConsultaVerificarJobOcr($resposta);
    $antes = estadoVerificarJobOcr($documento);

    expect(verificarJobOcr($documento))->toBe(DecisaoReenvioOcr::Reenviar)
        ->and(estadoVerificarJobOcr($documento))->toBe($antes)
        ->and(ProcessoOcrAviso::count())->toBe(0);

    Http::assertSentCount(1);
})->with([
    '404' => ['404'],
    'failed' => ['failed'],
    'cancelled' => ['cancelled'],
]);

it('pending ou running com idade < M: Aguardar, e o documento segue enviado com o job conhecido', function (string $status, int $idade) {
    [, $documento] = documentoEnviadoVerificarJobOcr($idade);
    simularConsultaVerificarJobOcr($status);
    $antes = estadoVerificarJobOcr($documento);

    expect(verificarJobOcr($documento))->toBe(DecisaoReenvioOcr::Aguardar)
        ->and(estadoVerificarJobOcr($documento))->toBe($antes)
        ->and(ProcessoOcrAviso::count())->toBe(0);
})->with(['pending', 'running'])->with([
    'idade A' => [20],
    'idade A + 1' => [21],
    'idade M - 1' => [59],
]);

it('pending ou running com idade ≥ M: Reenviar, porque o job é tido como travado (R6-3)', function (string $status, int $idade) {
    [, $documento] = documentoEnviadoVerificarJobOcr($idade);
    simularConsultaVerificarJobOcr($status);
    $antes = estadoVerificarJobOcr($documento);

    expect(verificarJobOcr($documento))->toBe(DecisaoReenvioOcr::Reenviar)
        ->and(estadoVerificarJobOcr($documento))->toBe($antes);
})->with(['pending', 'running'])->with([
    'idade M' => [60],
    'idade M + 30' => [90],
]);

it('a idade máxima M vem da configuração', function () {
    config()->set('services.n8n_ocr.idade_maxima_absoluta_minutos', 30);
    [, $documento] = documentoEnviadoVerificarJobOcr(30);
    simularConsultaVerificarJobOcr('running');

    expect(verificarJobOcr($documento))->toBe(DecisaoReenvioOcr::Reenviar);

    [, $novo] = documentoEnviadoVerificarJobOcr(29);

    expect(verificarJobOcr($novo))->toBe(DecisaoReenvioOcr::Aguardar);
});

it('running sem a data do envio: Reenviar, sem ficar preso a um job sem idade', function () {
    [, $documento] = documentoEnviadoVerificarJobOcr(atributos: ['ocr_enviado_em' => null]);
    simularConsultaVerificarJobOcr('running');

    expect(verificarJobOcr($documento))->toBe(DecisaoReenvioOcr::Reenviar);
});

it('consulta com falha: Aguardar, com Log::warning e sem mudar o documento (R6-2)', function (Closure $resposta) {
    [, $documento] = documentoEnviadoVerificarJobOcr();
    simularConsultaVerificarJobOcr($resposta);
    $antes = estadoVerificarJobOcr($documento);

    expect(verificarJobOcr($documento))->toBe(DecisaoReenvioOcr::Aguardar)
        ->and(estadoVerificarJobOcr($documento))->toBe($antes)
        ->and(ProcessoOcrAviso::count())->toBe(0);

    $avisos = collect($this->logs)->filter(fn (MessageLogged $log) => $log->level === 'warning'
        && $log->message === "[ProcessoDocumento:{$documento->id}] consulta do job falhou");

    expect($avisos)->toHaveCount(1)
        ->and($avisos->first()->context['job_id'])->toBe(JOB_ANTIGO_VERIFICAR_JOB_OCR)
        ->and(json_encode($avisos->first()->context))->not->toContain(TOKEN_WORKFLOW_VERIFICAR_JOB_OCR);
})->with([
    '503' => [fn () => Http::response('Service Unavailable', 503)],
    '500' => [fn () => Http::response(['message' => 'erro'], 500)],
    'tempo esgotado' => [fn (Request $request) => Http::failedConnection('cURL error 28: Operation timed out')($request)],
    'status fora do contrato' => [fn () => Http::response(['id' => JOB_ANTIGO_VERIFICAR_JOB_OCR, 'status' => 'queued'], 200)],
]);

it('enviado sem job: Reenviar sem consultar', function () {
    [, $documento] = documentoEnviadoVerificarJobOcr(atributos: ['ocr_job_id' => null]);
    Http::fake();

    expect(verificarJobOcr($documento))->toBe(DecisaoReenvioOcr::Reenviar);

    Http::assertNothingSent();
});

it('documento que não está aguardando retorno não é consultado', function (?string $situacao) {
    [, $documento] = documentoEnviadoVerificarJobOcr(atributos: ['ocr_situacao' => $situacao]);
    Http::fake();

    expect(fn () => verificarJobOcr($documento))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with([
    'pendente (null)' => [null],
    'pendente' => [ProcessoDocumento::OCR_PENDENTE],
    'processado' => [ProcessoDocumento::OCR_PROCESSADO],
    'erro' => [ProcessoDocumento::OCR_ERRO],
]);
