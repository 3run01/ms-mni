<?php

use App\Models\CredencialPje;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('cifra login e senha em repouso', function () {
    $c = CredencialPje::factory()->create(['login' => '12345678900', 'senha' => 'segredo-pje']);

    $this->assertDatabaseMissing('credenciais_pje', ['login' => '12345678900']);
    $this->assertDatabaseMissing('credenciais_pje', ['senha' => 'segredo-pje']);
    expect($c->fresh()->login)->toBe('12345678900')
        ->and($c->fresh()->senha)->toBe('segredo-pje');
});

it('gera uuid e login_mascarado', function () {
    $c = CredencialPje::factory()->create(['login' => '12345678900']);

    expect($c->uuid)->not->toBeNull()
        ->and($c->login_mascarado)->toBe('123*****900');
});

it('mascara login curto sem vazar conteúdo', function () {
    $c = CredencialPje::factory()->create(['login' => 'ab']);

    expect($c->login_mascarado)->toBe('******');
});

it('não expõe login, senha nem hash na serialização', function () {
    $c = CredencialPje::factory()->create(['login' => '12345678900', 'senha' => 'segredo-pje']);

    $json = $c->fresh()->toArray();

    expect($json)->not->toHaveKeys(['login', 'senha', 'login_hash', 'id'])
        ->and($json)->toHaveKey('uuid');
});
