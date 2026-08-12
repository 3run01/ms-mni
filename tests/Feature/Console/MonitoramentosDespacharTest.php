<?php

use App\Jobs\ExecutarMonitoramentoProcessoJob;
use App\Models\ProcessoMonitoramento;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;

uses(DatabaseTransactions::class);

beforeEach(fn () => Queue::fake());

it('despacha só os vencidos e ativos na fila monitoramento', function () {
    $vencido = ProcessoMonitoramento::factory()->vencido()->create();
    ProcessoMonitoramento::factory()->create(['proxima_execucao_em' => now()->addHour()]);
    ProcessoMonitoramento::factory()->vencido()->pausado()->create();
    ProcessoMonitoramento::factory()->vencido()->suspenso()->create();
    ProcessoMonitoramento::factory()->vencido()->create(['bloqueado_ate' => now()->addHour()]);

    $this->artisan('monitoramentos:despachar')
        ->expectsOutputToContain('1 monitoramento(s) despachado(s).')
        ->assertSuccessful();

    Queue::assertPushed(ExecutarMonitoramentoProcessoJob::class, 1);
    Queue::assertPushed(ExecutarMonitoramentoProcessoJob::class, fn ($job) => $job->monitoramentoId === $vencido->id
        && $job->queue === 'monitoramento');
});

it('reagenda pelo intervalo e aplica o lock de despacho', function () {
    $monitoramento = ProcessoMonitoramento::factory()->vencido()->create(['intervalo_horas' => 6]);

    $this->artisan('monitoramentos:despachar')->assertSuccessful();

    $atualizado = $monitoramento->fresh();

    expect($atualizado->proxima_execucao_em->diffInMinutes(now()->addHours(6), true))->toBeLessThan(2)
        ->and($atualizado->bloqueado_ate)->not->toBeNull()
        ->and($atualizado->bloqueado_ate->diffInMinutes(now()->addMinutes(120), true))->toBeLessThan(2);
});

it('segunda rodada imediata não redespacha o mesmo monitoramento', function () {
    ProcessoMonitoramento::factory()->vencido()->create();

    $this->artisan('monitoramentos:despachar')->assertSuccessful();
    $this->artisan('monitoramentos:despachar')
        ->expectsOutputToContain('0 monitoramento(s) despachado(s).')
        ->assertSuccessful();

    Queue::assertPushed(ExecutarMonitoramentoProcessoJob::class, 1);
});

it('recupera monitoramento com lock expirado', function () {
    $preso = ProcessoMonitoramento::factory()->vencido()->create([
        'bloqueado_ate' => now()->subMinute(),
    ]);

    $this->artisan('monitoramentos:despachar')->assertSuccessful();

    Queue::assertPushed(ExecutarMonitoramentoProcessoJob::class, fn ($job) => $job->monitoramentoId === $preso->id);
});

it('sem vencidos não despacha nada', function () {
    ProcessoMonitoramento::factory()->create(['proxima_execucao_em' => now()->addHour()]);

    $this->artisan('monitoramentos:despachar')
        ->expectsOutputToContain('0 monitoramento(s) despachado(s).')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});
