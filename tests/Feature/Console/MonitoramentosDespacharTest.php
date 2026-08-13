<?php

use App\Jobs\ExecutarMonitoramentoProcessoJob;
use App\Models\ProcessoMonitoramento;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;

uses(DatabaseTransactions::class);

beforeEach(fn () => Queue::fake());

/**
 * Quantas vezes o monitoramento indicado foi enfileirado. As asserções são
 * sempre por id: o comando é global e um banco de desenvolvimento pode ter
 * outros monitoramentos vencidos, que não são problema deste teste.
 */
function despachosDe(ProcessoMonitoramento $monitoramento): int
{
    return Queue::pushed(
        ExecutarMonitoramentoProcessoJob::class,
        fn ($job) => $job->monitoramentoId === $monitoramento->id
    )->count();
}

it('despacha só os vencidos e ativos na fila monitoramento', function () {
    $vencido = ProcessoMonitoramento::factory()->vencido()->create();
    $futuro = ProcessoMonitoramento::factory()->create(['proxima_execucao_em' => now()->addHour()]);
    $pausado = ProcessoMonitoramento::factory()->vencido()->pausado()->create();
    $suspenso = ProcessoMonitoramento::factory()->vencido()->suspenso()->create();
    $bloqueado = ProcessoMonitoramento::factory()->vencido()->create(['bloqueado_ate' => now()->addHour()]);

    $this->artisan('monitoramentos:despachar')->assertSuccessful();

    expect(despachosDe($vencido))->toBe(1)
        ->and(despachosDe($futuro))->toBe(0)
        ->and(despachosDe($pausado))->toBe(0)
        ->and(despachosDe($suspenso))->toBe(0)
        ->and(despachosDe($bloqueado))->toBe(0);

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
    $monitoramento = ProcessoMonitoramento::factory()->vencido()->create();

    $this->artisan('monitoramentos:despachar')->assertSuccessful();
    $this->artisan('monitoramentos:despachar')->assertSuccessful();

    expect(despachosDe($monitoramento))->toBe(1);
});

it('recupera monitoramento com lock expirado', function () {
    $preso = ProcessoMonitoramento::factory()->vencido()->create([
        'bloqueado_ate' => now()->subMinute(),
    ]);

    $this->artisan('monitoramentos:despachar')->assertSuccessful();

    expect(despachosDe($preso))->toBe(1);
});

it('não despacha monitoramento com execução ainda no futuro', function () {
    $futuro = ProcessoMonitoramento::factory()->create(['proxima_execucao_em' => now()->addHour()]);

    $this->artisan('monitoramentos:despachar')->assertSuccessful();

    expect(despachosDe($futuro))->toBe(0);
});
