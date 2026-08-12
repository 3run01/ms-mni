<?php

use App\Models\ProcessoMonitoramento;
use App\Models\ProcessoMonitoramentoExecucao;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('gera uuid ao criar', function () {
    $m = ProcessoMonitoramento::factory()->create();

    expect($m->uuid)->not->toBeNull()
        ->and($m->proxima_execucao_em)->not->toBeNull();
});

it('scope vencidos pega só ativo, vencido e não bloqueado', function () {
    $vencido = ProcessoMonitoramento::factory()->vencido()->create();
    ProcessoMonitoramento::factory()->create(['proxima_execucao_em' => now()->addHour()]);
    ProcessoMonitoramento::factory()->vencido()->pausado()->create();
    ProcessoMonitoramento::factory()->vencido()->suspenso()->create();
    ProcessoMonitoramento::factory()->vencido()->create(['bloqueado_ate' => now()->addHour()]);
    $lockExpirado = ProcessoMonitoramento::factory()->vencido()->create(['bloqueado_ate' => now()->subMinute()]);

    $ids = ProcessoMonitoramento::vencidos()->pluck('id');

    expect($ids)->toContain($vencido->id)
        ->toContain($lockExpirado->id)
        ->toHaveCount(2);
});

it('scope doToken isola por api_token_id', function () {
    $meu = ProcessoMonitoramento::factory()->create();
    $outro = ProcessoMonitoramento::factory()->create();

    $ids = ProcessoMonitoramento::doToken($meu->api_token_id)->pluck('id');

    expect($ids)->toContain($meu->id)->not->toContain($outro->id);
});

it('unique parcial permite recriar após cancelamento', function () {
    $m = ProcessoMonitoramento::factory()->create();

    $m->update(['status' => ProcessoMonitoramento::STATUS_CANCELADO]);
    $m->delete();

    $novo = ProcessoMonitoramento::factory()->create([
        'api_token_id' => $m->api_token_id,
        'tribunal_id' => $m->tribunal_id,
        'numero_processo' => $m->numero_processo,
    ]);

    expect($novo->id)->not->toBe($m->id);
});

it('execucoes relaciona e delta é json', function () {
    $e = ProcessoMonitoramentoExecucao::factory()->create(['delta' => ['movimentos' => []]]);

    expect($e->fresh()->delta)->toBeArray()
        ->and($e->monitoramento)->not->toBeNull();
});
