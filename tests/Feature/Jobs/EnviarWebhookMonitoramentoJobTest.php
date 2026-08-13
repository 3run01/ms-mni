<?php

use App\Jobs\EnviarWebhookMonitoramentoJob;
use App\Models\ProcessoMonitoramento;
use App\Models\ProcessoMonitoramentoExecucao;
use App\Services\Callback\CallbackNotifier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->monitoramento = ProcessoMonitoramento::factory()->create([
        'callback_url' => 'https://example.com/webhook',
        'callback_token' => 'tok-cliente',
    ]);
});

function rodarWebhook(ProcessoMonitoramentoExecucao $execucao): void
{
    (new EnviarWebhookMonitoramentoJob($execucao->id))->handle(app(CallbackNotifier::class));
}

it('envia payload de sucesso com deltas, headers e marca envio', function () {
    Http::fake(['*' => Http::response('', 200)]);

    $execucao = ProcessoMonitoramentoExecucao::factory()->sucesso()->create([
        'monitoramento_id' => $this->monitoramento->id,
        'delta' => [
            'primeira_execucao' => false,
            'truncado' => false,
            'movimentos' => [['identificador_movimento' => 'MOV-1']],
            'documentos' => [['id_documento' => 900001]],
        ],
    ]);

    rodarWebhook($execucao);

    Http::assertSent(function ($request) use ($execucao) {
        return $request->url() === 'https://example.com/webhook'
            && $request->hasHeader('X-API-Token', 'tok-cliente')
            && $request->hasHeader('X-Evento', 'processo.monitoramento.executado')
            && $request->hasHeader('X-Idempotency-Key', $execucao->uuid)
            && $request['evento'] === 'processo.monitoramento.executado'
            && $request['monitoramento_id'] === $this->monitoramento->uuid
            && $request['execucao_id'] === $execucao->uuid
            && $request['numero_processo'] === $this->monitoramento->numero_processo
            && $request['status'] === 'sucesso'
            && $request['houve_alteracao'] === true
            && $request['resumo']['movimentos_novos'] === 2
            && $request['resumo']['documentos_novos'] === 1
            && $request['resumo']['truncado'] === false
            && $request['movimentos'][0]['identificador_movimento'] === 'MOV-1'
            && $request['documentos'][0]['id_documento'] === 900001
            && $request['proxima_execucao_em'] !== null;
    });

    expect($execucao->fresh()->webhook_enviado_em)->not->toBeNull()
        ->and($execucao->fresh()->webhook_status_http)->toBe(200)
        ->and($execucao->fresh()->webhook_tentativas)->toBe(1);
});

it('envia payload de falha com erro_resumo e status do monitoramento', function () {
    Http::fake(['*' => Http::response('', 200)]);

    $this->monitoramento->update([
        'falhas_consecutivas' => 5,
        'status' => ProcessoMonitoramento::STATUS_SUSPENSO,
    ]);

    $execucao = ProcessoMonitoramentoExecucao::factory()->falha()->create([
        'monitoramento_id' => $this->monitoramento->id,
        'erro_resumo' => 'MNI: credenciais inválidas',
    ]);

    rodarWebhook($execucao);

    Http::assertSent(fn ($request) => $request['status'] === 'falha'
        && $request['houve_alteracao'] === false
        && $request['erro_resumo'] === 'MNI: credenciais inválidas'
        && $request['falhas_consecutivas'] === 5
        && $request['monitoramento_status'] === 'suspenso'
        && ! isset($request['movimentos']));
});

it('4xx falha permanente sem marcar envio', function () {
    Http::fake(['*' => Http::response('rejeitado', 422)]);

    $execucao = ProcessoMonitoramentoExecucao::factory()->create([
        'monitoramento_id' => $this->monitoramento->id,
    ]);

    rodarWebhook($execucao);

    expect($execucao->fresh()->webhook_enviado_em)->toBeNull()
        ->and($execucao->fresh()->webhook_status_http)->toBe(422);
});

it('5xx propaga a exceção para a fila retentar', function () {
    Http::fake(['*' => Http::response('erro', 500)]);

    $execucao = ProcessoMonitoramentoExecucao::factory()->create([
        'monitoramento_id' => $this->monitoramento->id,
    ]);

    expect(fn () => rodarWebhook($execucao))->toThrow(\Illuminate\Http\Client\RequestException::class);

    expect($execucao->fresh()->webhook_enviado_em)->toBeNull()
        ->and($execucao->fresh()->webhook_tentativas)->toBe(1);
});

it('não reenvia execução já notificada', function () {
    Http::fake(['*' => Http::response('', 200)]);

    $execucao = ProcessoMonitoramentoExecucao::factory()->webhookEnviado()->create([
        'monitoramento_id' => $this->monitoramento->id,
    ]);

    rodarWebhook($execucao);

    Http::assertNothingSent();
    expect($execucao->fresh()->webhook_tentativas)->toBe(0);
});

it('não lança quando a execução não existe', function () {
    Http::fake(['*' => Http::response('', 200)]);

    (new EnviarWebhookMonitoramentoJob(999999))->handle(app(CallbackNotifier::class));

    Http::assertNothingSent();
});

it('notifica mesmo com monitoramento cancelado (soft deleted)', function () {
    Http::fake(['*' => Http::response('', 200)]);

    $execucao = ProcessoMonitoramentoExecucao::factory()->create([
        'monitoramento_id' => $this->monitoramento->id,
    ]);

    $this->monitoramento->delete();

    rodarWebhook($execucao);

    Http::assertSent(fn ($request) => $request['execucao_id'] === $execucao->uuid);
    expect($execucao->fresh()->webhook_enviado_em)->not->toBeNull();
});
