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
