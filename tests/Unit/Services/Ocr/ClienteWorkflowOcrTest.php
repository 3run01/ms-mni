<?php

use App\Exceptions\FalhaTransitoriaWorkflowOcrException;
use App\Services\Ocr\ClienteWorkflowOcr;
use App\Services\Ocr\ResultadoEnvioOcr;
use App\Services\Ocr\SituacaoJobOcr;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;

/*
 * Cliente do workflow do OCR (T11): CT-03, CT-05 e CT-07 de
 * `.spec/features/ocr-do-processo-para-o-sistema/workflow-ocr.openapi.yaml`.
 * O cliente só traduz: 202/422 no envio, o enum no GET, 200 no DELETE, e o
 * resto vira FalhaTransitoriaWorkflowOcrException.
 */

const BASE_CLIENTE_WORKFLOW_OCR = 'http://n8n.test/webhook/juridico-ocr';
const TOKEN_CLIENTE_WORKFLOW_OCR = 'token-workflow-3f9';
const JOB_CLIENTE_WORKFLOW_OCR = '6f1c2a9e-4b7d-4e2a-9c1f-0d3b5a7e9f21';
const URL_S3_CLIENTE_WORKFLOW_OCR = 'https://bucket.s3.sa-east-1.amazonaws.com/documentos-processos/12345674220268260100/930001.pdf?X-Amz-Signature=segredo-s3-71c';

beforeEach(function () {
    config()->set('services.n8n_ocr.url', BASE_CLIENTE_WORKFLOW_OCR.'/');
    config()->set('services.n8n_ocr.token', TOKEN_CLIENTE_WORKFLOW_OCR);
    config()->set('services.n8n_ocr.timeout_http_segundos', 30);
});

function corpoClienteWorkflowOcr(): array
{
    return [
        'url' => URL_S3_CLIENTE_WORKFLOW_OCR,
        'filename' => '930001.pdf',
        'webhook_url' => 'https://ms-mni.example/api/ocr/webhook?documento=1&expires=1&signature=assinatura',
        'base_conhecimento' => 'base-processos',
        'metadata' => [
            'numero_processo' => '12345674220268260100',
            'tribunal_id' => 12,
            'numero_documento' => '930001',
            'tipo_documento' => 'Petição Inicial',
            'descricao' => 'Petição inicial',
            'movimento' => 'MOV-1',
            'mimetype' => 'application/pdf',
            'data_juntada' => '2026-01-05T10:00:00+00:00',
            'nivel_sigilo' => 0,
        ],
    ];
}

function respostaJobClienteWorkflowOcr(string $status): array
{
    return [
        'id' => JOB_CLIENTE_WORKFLOW_OCR,
        'status' => $status,
        'filename' => '930001.pdf',
        'progress' => 40,
        'progress_message' => 'Indexando',
        'error' => null,
        'created_at' => '2026-11-06T10:00:00Z',
        'updated_at' => '2026-11-06T10:01:00Z',
    ];
}

function enviarClienteWorkflowOcr(): ResultadoEnvioOcr
{
    return app(ClienteWorkflowOcr::class)->enviarDocumento(corpoClienteWorkflowOcr());
}

function falhaClienteWorkflowOcr(Closure $chamada): FalhaTransitoriaWorkflowOcrException
{
    try {
        $chamada();
    } catch (FalhaTransitoriaWorkflowOcrException $e) {
        return $e;
    }

    throw new RuntimeException('Esperava FalhaTransitoriaWorkflowOcrException');
}

function contratoWorkflowOcr(): array
{
    return Yaml::parseFile(base_path('.spec/features/ocr-do-processo-para-o-sistema/workflow-ocr.openapi.yaml'));
}

// ---------- envio: POST documents/process (CT-03) ----------

it('envio manda o corpo com Bearer para documents/process e o 202 com id fica aceito', function () {
    Http::fake([
        BASE_CLIENTE_WORKFLOW_OCR.'/documents/process' => Http::response(
            ['id' => JOB_CLIENTE_WORKFLOW_OCR, 'status' => 'pending', 'error' => null],
            202,
        ),
    ]);

    $resultado = enviarClienteWorkflowOcr();

    expect($resultado->aceito)->toBeTrue()
        ->and($resultado->jobId)->toBe(JOB_CLIENTE_WORKFLOW_OCR)
        ->and($resultado->detalhe)->toBeNull();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === BASE_CLIENTE_WORKFLOW_OCR.'/documents/process'
        && $request->header('Authorization') === ['Bearer '.TOKEN_CLIENTE_WORKFLOW_OCR]
        && $request->hasHeader('Accept', 'application/json')
        && $request->data() === corpoClienteWorkflowOcr());
});

it('envio com 422 fica recusado com o detail do workflow', function () {
    Http::fake(['*' => Http::response(['detail' => 'filename sem extensão aceita'], 422)]);

    $resultado = enviarClienteWorkflowOcr();

    expect($resultado->aceito)->toBeFalse()
        ->and($resultado->jobId)->toBeNull()
        ->and($resultado->detalhe)->toBe('filename sem extensão aceita');
});

it('envio com 422 sem detail fica recusado com um motivo padrão', function () {
    Http::fake(['*' => Http::response('', 422)]);

    $resultado = enviarClienteWorkflowOcr();

    expect($resultado->aceito)->toBeFalse()
        ->and($resultado->detalhe)->toBe('Pedido recusado pelo workflow do OCR (HTTP 422)');
});

it('envio com resposta que não decide lança falha transitória', function (int $status, mixed $corpo) {
    Http::fake(['*' => Http::response($corpo, $status)]);

    $falha = falhaClienteWorkflowOcr(fn () => enviarClienteWorkflowOcr());

    expect($falha->statusHttp)->toBe($status);
})->with([
    '500' => [500, ['message' => 'Erro interno']],
    '502' => [502, ''],
    '503' => [503, ''],
    '202 sem id' => [202, ['status' => 'pending', 'error' => null]],
    '202 com id vazio' => [202, ['id' => '', 'status' => 'pending']],
    '202 sem corpo JSON' => [202, 'aceito'],
    '200 fora do contrato' => [200, ['id' => JOB_CLIENTE_WORKFLOW_OCR]],
    '401 token recusado' => [401, ['message' => 'Authorization data is wrong!']],
]);

it('envio sem resposta (tempo esgotado ou rede) lança falha transitória com a causa', function () {
    Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out')]);

    $falha = falhaClienteWorkflowOcr(fn () => enviarClienteWorkflowOcr());

    expect($falha->statusHttp)->toBeNull()
        ->and($falha->getPrevious())->toBeInstanceOf(ConnectionException::class);
});

it('envio não leva o corpo da requisição nem da resposta ao log nem à exceção', function () {
    $logs = [];
    Event::listen(MessageLogged::class, function (MessageLogged $evento) use (&$logs) {
        $logs[] = $evento->message.' '.json_encode($evento->context);
    });

    Http::fake(['*' => Http::response(['eco' => URL_S3_CLIENTE_WORKFLOW_OCR, 'detalhe' => 'corpo-da-resposta-9d2'], 500)]);

    $falha = falhaClienteWorkflowOcr(fn () => enviarClienteWorkflowOcr());

    $texto = $falha->getMessage().' '.implode("\n", $logs);

    expect($logs)->not->toBeEmpty()
        ->and($texto)->toContain('500')
        ->and($texto)->not->toContain('segredo-s3-71c')
        ->and($texto)->not->toContain('corpo-da-resposta-9d2')
        ->and($texto)->not->toContain('Petição inicial')
        ->and($texto)->not->toContain(TOKEN_CLIENTE_WORKFLOW_OCR);
});

it('sem a base do workflow configurada lança falha transitória sem requisição', function () {
    config()->set('services.n8n_ocr.url', null);
    Http::fake();

    falhaClienteWorkflowOcr(fn () => enviarClienteWorkflowOcr());
    falhaClienteWorkflowOcr(fn () => app(ClienteWorkflowOcr::class)->consultarJob(JOB_CLIENTE_WORKFLOW_OCR));
    falhaClienteWorkflowOcr(fn () => app(ClienteWorkflowOcr::class)->cancelarJob(JOB_CLIENTE_WORKFLOW_OCR));

    Http::assertNothingSent();
});

// ---------- consulta: GET jobs/{job_id} (CT-05) ----------

it('consulta manda GET com Bearer para jobs/{id} e traduz o status do job', function (string $status, SituacaoJobOcr $esperada) {
    Http::fake([
        BASE_CLIENTE_WORKFLOW_OCR.'/jobs/'.JOB_CLIENTE_WORKFLOW_OCR => Http::response(respostaJobClienteWorkflowOcr($status), 200),
    ]);

    expect(app(ClienteWorkflowOcr::class)->consultarJob(JOB_CLIENTE_WORKFLOW_OCR))->toBe($esperada);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === BASE_CLIENTE_WORKFLOW_OCR.'/jobs/'.JOB_CLIENTE_WORKFLOW_OCR
        && $request->header('Authorization') === ['Bearer '.TOKEN_CLIENTE_WORKFLOW_OCR]);
})->with([
    'pending' => ['pending', SituacaoJobOcr::Pendente],
    'running' => ['running', SituacaoJobOcr::EmExecucao],
    'success' => ['success', SituacaoJobOcr::Sucesso],
    'failed' => ['failed', SituacaoJobOcr::Falhou],
    'cancelled' => ['cancelled', SituacaoJobOcr::Cancelado],
]);

it('consulta com 404 dá job Inexistente', function () {
    Http::fake(['*' => Http::response(['detail' => 'Job não encontrado'], 404)]);

    expect(app(ClienteWorkflowOcr::class)->consultarJob(JOB_CLIENTE_WORKFLOW_OCR))->toBe(SituacaoJobOcr::Inexistente);
});

it('consulta com resposta que não decide lança falha transitória', function (int $status, mixed $corpo) {
    Http::fake(['*' => Http::response($corpo, $status)]);

    $falha = falhaClienteWorkflowOcr(fn () => app(ClienteWorkflowOcr::class)->consultarJob(JOB_CLIENTE_WORKFLOW_OCR));

    expect($falha->statusHttp)->toBe($status);
})->with([
    'status desconhecido' => [200, respostaJobClienteWorkflowOcr('paused')],
    'status em maiúsculas' => [200, respostaJobClienteWorkflowOcr('SUCCESS')],
    'status "inexistente" no 200' => [200, respostaJobClienteWorkflowOcr('inexistente')],
    'sem status' => [200, ['id' => JOB_CLIENTE_WORKFLOW_OCR]],
    '200 sem JSON' => [200, 'ok'],
    '500' => [500, ''],
    '503' => [503, ''],
    '401' => [401, ''],
    '202' => [202, respostaJobClienteWorkflowOcr('success')],
]);

it('consulta sem resposta (tempo esgotado ou rede) lança falha transitória', function () {
    Http::fake(['*' => Http::failedConnection()]);

    $falha = falhaClienteWorkflowOcr(fn () => app(ClienteWorkflowOcr::class)->consultarJob(JOB_CLIENTE_WORKFLOW_OCR));

    expect($falha->statusHttp)->toBeNull();
});

it('consulta codifica o job_id no caminho', function () {
    Http::fake(['*' => Http::response(respostaJobClienteWorkflowOcr('running'), 200)]);

    app(ClienteWorkflowOcr::class)->consultarJob('a/b c');

    Http::assertSent(fn (Request $request) => $request->url() === BASE_CLIENTE_WORKFLOW_OCR.'/jobs/a%2Fb%20c');
});

it('consulta e cancelamento recusam job_id vazio sem requisição', function (string $metodo) {
    Http::fake();

    expect(fn () => app(ClienteWorkflowOcr::class)->{$metodo}('  '))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with(['consultarJob', 'cancelarJob']);

it('SituacaoJobOcr diz em andamento só para pending e running', function () {
    expect(SituacaoJobOcr::Pendente->emAndamento())->toBeTrue()
        ->and(SituacaoJobOcr::EmExecucao->emAndamento())->toBeTrue()
        ->and(SituacaoJobOcr::Sucesso->emAndamento())->toBeFalse()
        ->and(SituacaoJobOcr::Falhou->emAndamento())->toBeFalse()
        ->and(SituacaoJobOcr::Cancelado->emAndamento())->toBeFalse()
        ->and(SituacaoJobOcr::Inexistente->emAndamento())->toBeFalse();
});

// ---------- cancelamento: DELETE jobs/{job_id}/chunks (CT-07) ----------

it('cancelamento manda DELETE com Bearer e sem corpo para jobs/{id}/chunks e o 200 é ok', function () {
    Http::fake([
        BASE_CLIENTE_WORKFLOW_OCR.'/jobs/'.JOB_CLIENTE_WORKFLOW_OCR.'/chunks' => Http::response(
            ['job_id' => JOB_CLIENTE_WORKFLOW_OCR, 'deleted_chunks' => 12],
            200,
        ),
    ]);

    app(ClienteWorkflowOcr::class)->cancelarJob(JOB_CLIENTE_WORKFLOW_OCR);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === BASE_CLIENTE_WORKFLOW_OCR.'/jobs/'.JOB_CLIENTE_WORKFLOW_OCR.'/chunks'
        && $request->header('Authorization') === ['Bearer '.TOKEN_CLIENTE_WORKFLOW_OCR]
        && $request->body() === '');
});

it('cancelamento com resposta que não é 200 lança falha transitória', function (int $status) {
    Http::fake(['*' => Http::response('', $status)]);

    $falha = falhaClienteWorkflowOcr(fn () => app(ClienteWorkflowOcr::class)->cancelarJob(JOB_CLIENTE_WORKFLOW_OCR));

    expect($falha->statusHttp)->toBe($status);
})->with([500, 503, 404, 401, 204, 202]);

it('cancelamento sem resposta (tempo esgotado ou rede) lança falha transitória', function () {
    Http::fake(['*' => Http::failedConnection()]);

    $falha = falhaClienteWorkflowOcr(fn () => app(ClienteWorkflowOcr::class)->cancelarJob(JOB_CLIENTE_WORKFLOW_OCR));

    expect($falha->statusHttp)->toBeNull();
});

// ---------- formas do contrato (workflow-ocr.openapi.yaml) ----------

it('as operações e a autenticação do cliente batem com o contrato do workflow', function () {
    $contrato = contratoWorkflowOcr();

    expect($contrato['paths'])->toHaveKeys(['/documents/process', '/jobs/{job_id}', '/jobs/{job_id}/chunks'])
        ->and($contrato['paths']['/documents/process'])->toHaveKey('post')
        ->and($contrato['paths']['/jobs/{job_id}'])->toHaveKey('get')
        ->and($contrato['paths']['/jobs/{job_id}/chunks'])->toHaveKey('delete')
        ->and($contrato['components']['securitySchemes']['TokenDoWorkflow'])->toMatchArray([
            'type' => 'http',
            'scheme' => 'bearer',
        ])
        ->and(array_keys($contrato['paths']['/documents/process']['post']['responses']))->toEqualCanonicalizing(['202', '422', '5XX'])
        ->and(array_keys($contrato['paths']['/jobs/{job_id}']['get']['responses']))->toEqualCanonicalizing(['200', '404'])
        ->and(array_keys($contrato['paths']['/jobs/{job_id}/chunks']['delete']['responses']))->toEqualCanonicalizing(['200']);
});

it('o enum SituacaoJobOcr lê exatamente os status do contrato do workflow', function () {
    $status = contratoWorkflowOcr()['components']['schemas']['JobDoWorkflowOcr']['properties']['status']['enum'];

    expect($status)->toEqualCanonicalizing(['pending', 'running', 'success', 'failed', 'cancelled']);

    foreach ($status as $valor) {
        expect(SituacaoJobOcr::doStatusDoWorkflow($valor))->toBeInstanceOf(SituacaoJobOcr::class)
            ->not->toBe(SituacaoJobOcr::Inexistente);
    }
});

it('o 202 lido é o JobCriado do contrato e o 422 lido é o ErroDoWorkflow', function () {
    $schemas = contratoWorkflowOcr()['components']['schemas'];

    expect($schemas['JobCriado']['required'])->toContain('id')
        ->and($schemas['ErroDoWorkflow']['required'])->toBe(['detail'])
        ->and($schemas['ExclusaoDeTrechos']['required'])->toEqualCanonicalizing(['job_id', 'deleted_chunks']);
});

it('o corpo de envio dos testes tem as 5 chaves e o metadata do contrato', function () {
    $schemas = contratoWorkflowOcr()['components']['schemas'];
    $corpo = corpoClienteWorkflowOcr();

    expect(array_keys($corpo))->toEqualCanonicalizing($schemas['EnvioDeDocumentoAoOcr']['required'])
        ->and(array_keys($corpo['metadata']))->toEqualCanonicalizing($schemas['MetadataDocumentoDosAutos']['required'])
        ->and($corpo['metadata'])->not->toHaveKey('escritorio_id');
});
