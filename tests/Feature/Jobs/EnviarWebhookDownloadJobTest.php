<?php

use App\Jobs\EnviarWebhookDownloadJob;
use App\Models\ProcessoExportacao;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('notifica callback do chamador com download_url presigned em concluido', function () {
    Storage::fake('s3');
    Http::fake(['*' => Http::response('', 200)]);

    $e = ProcessoExportacao::factory()->create([
        'status' => ProcessoExportacao::STATUS_CONCLUIDO,
        's3_path' => 'downloads/1/abc.pdf',
        'tamanho_bytes' => 999,
        'callback_url' => 'https://example.com/webhook',
        'callback_token' => 'tok-xyz',
        'webhook_enviado_em' => null,
    ]);
    Storage::disk('s3')->put('downloads/1/abc.pdf', 'conteudo');

    EnviarWebhookDownloadJob::dispatchSync($e->id);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://example.com/webhook'
            && $request->hasHeader('X-API-Token', 'tok-xyz')
            && $request['status'] === 'concluido'
            && array_key_exists('download_url', $request->data())
            && $request['tamanho_bytes'] === 999
            && ! array_key_exists('s3_path', $request->data());
    });

    expect($e->fresh()->webhook_enviado_em)->not->toBeNull();
});

it('notifica erro_resumo em falhou', function () {
    Http::fake(['*' => Http::response('', 200)]);

    $e = ProcessoExportacao::factory()->create([
        'status' => ProcessoExportacao::STATUS_FALHOU,
        'erro_resumo' => 'sem documentos',
        'callback_url' => 'https://example.com/webhook',
        'callback_token' => 'tok',
        'webhook_enviado_em' => null,
    ]);

    EnviarWebhookDownloadJob::dispatchSync($e->id);

    Http::assertSent(fn ($r) => $r['status'] === 'falhou' && $r['erro_resumo'] === 'sem documentos');
});
