<?php

use App\Models\ProcessoExportacao;
use App\Services\Exportacao\WebhookDownloadClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;

uses(DatabaseTransactions::class);

beforeEach(function () {
    config()->set('services.sim_webhook_download.url', 'https://sim.test/webhook/download');
    config()->set('services.sim_webhook_download.token', 'test-token');
    config()->set('services.sim_webhook_download.timeout', 10);
});

it('envia payload de sucesso com s3_path e tamanho_bytes', function () {
    Http::fake(['sim.test/*' => Http::response(['message' => 'OK', 'download_id' => 1], 200)]);

    $exportacao = ProcessoExportacao::factory()->concluido()->create([
        'user_id' => 152,
        'titulo' => 'Processo X — PDF',
        'tamanho_bytes' => 4582934,
    ]);

    (new WebhookDownloadClient())->notificar($exportacao);

    Http::assertSent(function ($request) use ($exportacao) {
        return $request->url() === 'https://sim.test/webhook/download'
            && $request->header('X-API-Token')[0] === 'test-token'
            && $request['user_id'] === 152
            && $request['titulo'] === 'Processo X — PDF'
            && $request['formato'] === 'pdf'
            && $request['status'] === 'concluido'
            && $request['s3_path'] === $exportacao->s3_path
            && $request['tamanho_bytes'] === 4582934;
    });
});

it('envia payload de falha apenas com erro_resumo', function () {
    Http::fake(['sim.test/*' => Http::response(['message' => 'OK'], 200)]);

    $exportacao = ProcessoExportacao::factory()->falhou('Indisponível.')->create([
        'user_id' => 7,
    ]);

    (new WebhookDownloadClient())->notificar($exportacao);

    Http::assertSent(function ($request) {
        return $request['status'] === 'falhou'
            && $request['erro_resumo'] === 'Indisponível.'
            && !isset($request['s3_path'])
            && !isset($request['tamanho_bytes']);
    });
});

it('lança exception em 5xx do SIM', function () {
    Http::fake(['sim.test/*' => Http::response('boom', 503)]);

    $exportacao = ProcessoExportacao::factory()->concluido()->create();

    expect(fn () => (new WebhookDownloadClient())->notificar($exportacao))
        ->toThrow(Illuminate\Http\Client\RequestException::class);
});

it('lança exception em timeout/conexão recusada', function () {
    Http::fake(function () {
        throw new Illuminate\Http\Client\ConnectionException('timeout');
    });

    $exportacao = ProcessoExportacao::factory()->concluido()->create();

    expect(fn () => (new WebhookDownloadClient())->notificar($exportacao))
        ->toThrow(Illuminate\Http\Client\ConnectionException::class);
});

it('lança WebhookDownloadClient\\WebhookPermanentException em 4xx (não retentável)', function () {
    Http::fake(['sim.test/*' => Http::response(['error' => 'Validação'], 422)]);

    $exportacao = ProcessoExportacao::factory()->concluido()->create();

    expect(fn () => (new WebhookDownloadClient())->notificar($exportacao))
        ->toThrow(App\Services\Exportacao\WebhookPermanentException::class);
});
