<?php
// tests/Feature/ApiTokenModelTest.php

use App\Models\ApiToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('generatePlainToken gera token com prefixo mni_ e 52 chars', function () {
    $plain = ApiToken::generatePlainToken();

    expect($plain)->toStartWith('mni_')->toHaveLength(52);
});

it('findValid retorna token ativo sem expiração', function () {
    $token = criarTokenApi('mni_teste_valido');

    expect(ApiToken::findValid('mni_teste_valido')?->id)->toBe($token->id);
});

it('findValid retorna null para token inexistente', function () {
    expect(ApiToken::findValid('mni_nao_existe'))->toBeNull();
});

it('findValid retorna null para token inativo', function () {
    criarTokenApi('mni_teste_inativo')->update(['ativo' => false]);

    expect(ApiToken::findValid('mni_teste_inativo'))->toBeNull();
});

it('findValid retorna null para token expirado', function () {
    criarTokenApi('mni_teste_expirado')->update(['expires_at' => now()->subMinute()]);

    expect(ApiToken::findValid('mni_teste_expirado'))->toBeNull();
});

it('findValid retorna token com expiração futura', function () {
    criarTokenApi('mni_teste_futuro')->update(['expires_at' => now()->addDay()]);

    expect(ApiToken::findValid('mni_teste_futuro'))->not->toBeNull();
});

it('registrarUso grava last_used_at sem tocar updated_at', function () {
    $token = criarTokenApi();
    $updatedAt = $token->fresh()->updated_at;

    $token->registrarUso();

    $fresh = $token->fresh();
    expect($fresh->last_used_at)->not->toBeNull();
    expect($fresh->updated_at->equalTo($updatedAt))->toBeTrue();
});

it('registrarUso não regrava quando uso recente (menos de 1 minuto)', function () {
    $token = criarTokenApi();
    $primeiroUso = now()->subSeconds(30);
    $token->forceFill(['last_used_at' => $primeiroUso])->saveQuietly();
    // Coluna timestamp(0) no Postgres trunca microssegundos; comparamos com o
    // valor já persistido (round-tripped) em vez do Carbon original em memória.
    $primeiroUso = $token->fresh()->last_used_at;

    $token->refresh()->registrarUso();

    expect($token->fresh()->last_used_at->equalTo($primeiroUso))->toBeTrue();
});
