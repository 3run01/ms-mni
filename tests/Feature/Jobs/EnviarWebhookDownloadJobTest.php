<?php

use App\Jobs\EnviarWebhookDownloadJob;
use App\Models\ProcessoExportacao;
use App\Services\Exportacao\WebhookDownloadClient;
use App\Services\Exportacao\WebhookPermanentException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;

uses(DatabaseTransactions::class);

it('chama o client e marca webhook_enviado_em quando sucesso', function () {
    $exportacao = ProcessoExportacao::factory()->concluido()->create();

    $client = Mockery::mock(WebhookDownloadClient::class);
    $client->shouldReceive('notificar')->once();
    app()->instance(WebhookDownloadClient::class, $client);

    (new EnviarWebhookDownloadJob($exportacao->id))->handle(app(WebhookDownloadClient::class));

    $exportacao->refresh();
    expect($exportacao->webhook_enviado_em)->not->toBeNull();
    expect($exportacao->webhook_tentativas)->toBe(1);
});

it('é idempotente: não chama o client se webhook_enviado_em já preenchido', function () {
    $exportacao = ProcessoExportacao::factory()->concluido()->webhookEnviado()->create();

    $client = Mockery::mock(WebhookDownloadClient::class);
    $client->shouldNotReceive('notificar');
    app()->instance(WebhookDownloadClient::class, $client);

    (new EnviarWebhookDownloadJob($exportacao->id))->handle(app(WebhookDownloadClient::class));

    $exportacao->refresh();
    expect($exportacao->webhook_tentativas)->toBe(1);
});

it('relança a exception em erro retentável (5xx) e incrementa tentativas', function () {
    $exportacao = ProcessoExportacao::factory()->concluido()->create();

    $client = Mockery::mock(WebhookDownloadClient::class);
    $client->shouldReceive('notificar')->andThrow(new RuntimeException('5xx'));
    app()->instance(WebhookDownloadClient::class, $client);

    expect(fn () => (new EnviarWebhookDownloadJob($exportacao->id))->handle(app(WebhookDownloadClient::class)))
        ->toThrow(RuntimeException::class);

    $exportacao->refresh();
    expect($exportacao->webhook_tentativas)->toBe(1);
    expect($exportacao->webhook_enviado_em)->toBeNull();
});

it('em erro permanente (4xx) marca como falho via fail() sem relançar', function () {
    $exportacao = ProcessoExportacao::factory()->concluido()->create();

    $client = Mockery::mock(WebhookDownloadClient::class);
    $client->shouldReceive('notificar')->andThrow(new WebhookPermanentException(422, 'rejected'));
    app()->instance(WebhookDownloadClient::class, $client);

    Log::spy();

    $job = new EnviarWebhookDownloadJob($exportacao->id);
    $job->handle(app(WebhookDownloadClient::class));

    $exportacao->refresh();
    expect($exportacao->webhook_tentativas)->toBe(1);
    expect($exportacao->webhook_enviado_em)->toBeNull();
    Log::shouldHaveReceived('critical')->once();
});

it('retorna sem efeito quando exportacao_id não existe', function () {
    $client = Mockery::mock(WebhookDownloadClient::class);
    $client->shouldNotReceive('notificar');
    app()->instance(WebhookDownloadClient::class, $client);

    Log::spy();

    (new EnviarWebhookDownloadJob(999999))->handle(app(WebhookDownloadClient::class));

    Log::shouldHaveReceived('warning')->once();
});
