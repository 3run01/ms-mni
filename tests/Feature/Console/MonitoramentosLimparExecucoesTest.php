<?php

use App\Models\ProcessoMonitoramentoExecucao;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('remove execuções mais antigas que o padrão de 90 dias', function () {
    $antiga = ProcessoMonitoramentoExecucao::factory()->create(['created_at' => now()->subDays(100)]);
    $recente = ProcessoMonitoramentoExecucao::factory()->create(['created_at' => now()->subDays(10)]);

    $this->artisan('monitoramentos:limpar-execucoes')
        ->expectsOutputToContain('1 execução(ões) removida(s)')
        ->assertSuccessful();

    expect(ProcessoMonitoramentoExecucao::find($antiga->id))->toBeNull()
        ->and(ProcessoMonitoramentoExecucao::find($recente->id))->not->toBeNull();
});

it('respeita o parâmetro --dias', function () {
    $execucao = ProcessoMonitoramentoExecucao::factory()->create(['created_at' => now()->subDays(10)]);

    $this->artisan('monitoramentos:limpar-execucoes', ['--dias' => 5])->assertSuccessful();

    expect(ProcessoMonitoramentoExecucao::find($execucao->id))->toBeNull();
});

it('rejeita --dias menor que 1', function () {
    $this->artisan('monitoramentos:limpar-execucoes', ['--dias' => 0])
        ->expectsOutputToContain('--dias deve ser no mínimo 1')
        ->assertFailed();
});
