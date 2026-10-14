<?php

use App\Models\Tribunal;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(function () {
    criarTokenApi('tk-tribunais');
});

it('traz sigla e grau em todo tribunal de GET /api/tribunais (DEP-02)', function () {
    Tribunal::factory()->create(['nome' => 'TJZZ 1º Grau', 'sigla' => 'TJZZ', 'grau' => '1']);
    Tribunal::factory()->create(['nome' => 'TJZZ 2º Grau', 'sigla' => 'TJZZ', 'grau' => '2']);

    $tribunais = $this->withHeaders(['X-API-Token' => 'tk-tribunais'])
        ->getJson('/api/tribunais')
        ->assertOk()
        ->json();

    foreach ($tribunais as $tribunal) {
        expect($tribunal)->toHaveKeys(['sigla', 'grau']);
    }

    // duas linhas ativas com a mesma sigla, distinguidas pelo grau
    expect(collect($tribunais)->where('sigla', 'TJZZ')->pluck('grau')->sort()->values()->all())
        ->toBe(['1', '2']);
});

it('traz a chave grau com null quando não se aplica', function () {
    Tribunal::factory()->create(['nome' => 'TRT24 Teste', 'sigla' => 'TRT24', 'grau' => null]);

    $tribunal = collect(
        $this->withHeaders(['X-API-Token' => 'tk-tribunais'])->getJson('/api/tribunais')->json()
    )->firstWhere('nome', 'TRT24 Teste');

    expect($tribunal)->toHaveKey('grau');
    expect($tribunal['grau'])->toBeNull();
    expect($tribunal['sigla'])->toBe('TRT24');
});

it('traz sigla e grau em GET /api/tribunais/{uuid} (DEP-02)', function () {
    $tribunal = Tribunal::factory()->create(['sigla' => 'TRE-SP', 'grau' => '2']);

    $this->withHeaders(['X-API-Token' => 'tk-tribunais'])
        ->getJson("/api/tribunais/{$tribunal->uuid}")
        ->assertOk()
        ->assertJsonPath('sigla', 'TRE-SP')
        ->assertJsonPath('grau', '2');
});
