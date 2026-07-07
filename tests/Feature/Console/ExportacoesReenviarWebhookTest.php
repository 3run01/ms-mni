<?php

use App\Jobs\EnviarWebhookDownloadJob;
use App\Models\ProcessoExportacao;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;

uses(DatabaseTransactions::class);

it('com argumento, redespacha o job para a exportação especificada', function () {
    Queue::fake();

    $exportacao = ProcessoExportacao::factory()->concluido()->create();

    $this->artisan('exportacoes:reenviar-webhook', ['exportacao_id' => $exportacao->id])
        ->assertSuccessful();

    Queue::assertPushed(EnviarWebhookDownloadJob::class, fn ($j) => $j->exportacaoId === $exportacao->id);
});

it('falha quando exportacao_id não existe', function () {
    $this->artisan('exportacoes:reenviar-webhook', ['exportacao_id' => 999999])
        ->expectsOutputToContain('não encontrada')
        ->assertFailed();
});

it('sem argumento, lista pendentes >1h e redespacha após confirmação', function () {
    Queue::fake();

    $antiga = ProcessoExportacao::factory()->concluido()->create([
        'created_at' => now()->subHours(2),
    ]);
    $recente = ProcessoExportacao::factory()->concluido()->create([
        'created_at' => now()->subMinutes(30),
    ]);
    $jaEnviada = ProcessoExportacao::factory()->concluido()->webhookEnviado()->create([
        'created_at' => now()->subHours(2),
    ]);

    $this->artisan('exportacoes:reenviar-webhook')
        ->expectsConfirmation('Redespachar webhook para 1 exportação(ões)?', 'yes')
        ->assertSuccessful();

    Queue::assertPushed(EnviarWebhookDownloadJob::class, 1);
    Queue::assertPushed(EnviarWebhookDownloadJob::class, fn ($j) => $j->exportacaoId === $antiga->id);
});

it('flag --reset-tentativas zera webhook_tentativas antes de redespachar', function () {
    Queue::fake();

    $exportacao = ProcessoExportacao::factory()->concluido()->create([
        'webhook_tentativas' => 5,
    ]);

    $this->artisan('exportacoes:reenviar-webhook', [
        'exportacao_id' => $exportacao->id,
        '--reset-tentativas' => true,
    ])->assertSuccessful();

    expect($exportacao->fresh()->webhook_tentativas)->toBe(0);
});
