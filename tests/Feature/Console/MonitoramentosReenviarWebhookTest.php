<?php

use App\Jobs\EnviarWebhookMonitoramentoJob;
use App\Models\ProcessoMonitoramentoExecucao;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;

uses(DatabaseTransactions::class);

beforeEach(fn () => Queue::fake());

it('com argumento, redespacha o job da execução especificada', function () {
    $execucao = ProcessoMonitoramentoExecucao::factory()->create();

    $this->artisan('monitoramentos:reenviar-webhook', ['execucao_id' => $execucao->id])
        ->assertSuccessful();

    Queue::assertPushed(EnviarWebhookMonitoramentoJob::class, fn ($job) => $job->execucaoId === $execucao->id
        && $job->queue === 'monitoramento-webhook');
});

it('falha quando execucao_id não existe', function () {
    $this->artisan('monitoramentos:reenviar-webhook', ['execucao_id' => 999999])
        ->expectsOutputToContain('não encontrada')
        ->assertFailed();
});

it('sem argumento, redespacha só pendentes com mais de 1 hora', function () {
    $antiga = ProcessoMonitoramentoExecucao::factory()->create(['created_at' => now()->subHours(2)]);
    ProcessoMonitoramentoExecucao::factory()->create(['created_at' => now()->subMinutes(30)]);
    ProcessoMonitoramentoExecucao::factory()->webhookEnviado()->create(['created_at' => now()->subHours(2)]);

    $this->artisan('monitoramentos:reenviar-webhook')
        ->expectsConfirmation('Redespachar webhook para 1 execução(ões)?', 'yes')
        ->assertSuccessful();

    Queue::assertPushed(EnviarWebhookMonitoramentoJob::class, 1);
    Queue::assertPushed(EnviarWebhookMonitoramentoJob::class, fn ($job) => $job->execucaoId === $antiga->id);
});

it('flag --reset-tentativas zera o contador antes de redespachar', function () {
    $execucao = ProcessoMonitoramentoExecucao::factory()->create(['webhook_tentativas' => 5]);

    $this->artisan('monitoramentos:reenviar-webhook', [
        'execucao_id' => $execucao->id,
        '--reset-tentativas' => true,
    ])->assertSuccessful();

    expect($execucao->fresh()->webhook_tentativas)->toBe(0);
});
