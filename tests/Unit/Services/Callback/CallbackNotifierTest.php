<?php

use App\Services\Callback\CallbackNotifier;
use App\Services\Callback\CallbackPermanentException;
use Illuminate\Support\Facades\Http;

it('envia POST com X-API-Token e payload', function () {
    Http::fake(['*' => Http::response('', 200)]);

    (new CallbackNotifier())->notificar('https://example.com/webhook', 'tok-1', ['status' => 'concluido']);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://example.com/webhook'
            && $request->hasHeader('X-API-Token', 'tok-1')
            && $request['status'] === 'concluido';
    });
});

it('lança permanente em 4xx', function () {
    Http::fake(['*' => Http::response('rejeitado', 422)]);

    (new CallbackNotifier())->notificar('https://example.com/webhook', 'tok-1', []);
})->throws(CallbackPermanentException::class);

it('relança em 5xx', function () {
    Http::fake(['*' => Http::response('erro', 500)]);

    (new CallbackNotifier())->notificar('https://example.com/webhook', 'tok-1', []);
})->throws(\Illuminate\Http\Client\RequestException::class);

it('envia headers extras junto do X-API-Token', function () {
    Http::fake(['*' => Http::response('', 200)]);

    (new CallbackNotifier())->notificar('https://example.com/webhook', 'tok-1', [], [
        'X-Evento' => 'processo.monitoramento.executado',
        'X-Idempotency-Key' => 'uuid-1',
    ]);

    Http::assertSent(fn ($request) => $request->hasHeader('X-API-Token', 'tok-1')
        && $request->hasHeader('X-Evento', 'processo.monitoramento.executado')
        && $request->hasHeader('X-Idempotency-Key', 'uuid-1'));
});

it('header extra não sobrescreve o X-API-Token', function () {
    Http::fake(['*' => Http::response('', 200)]);

    (new CallbackNotifier())->notificar('https://example.com/webhook', 'tok-certo', [], [
        'X-API-Token' => 'tok-errado',
    ]);

    Http::assertSent(fn ($request) => $request->hasHeader('X-API-Token', 'tok-certo'));
});

it('retorna o status HTTP em caso de sucesso', function () {
    Http::fake(['*' => Http::response('', 202)]);

    $status = (new CallbackNotifier())->notificar('https://example.com/webhook', 'tok-1', []);

    expect($status)->toBe(202);
});
