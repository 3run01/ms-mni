<?php
// tests/Feature/Api/ValidateApiTokenTest.php

use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('aceita token válido da tabela', function () {
    criarTokenApi('mni_token_valido');

    $this->withHeaders(['X-API-Token' => 'mni_token_valido'])
        ->getJson('/api/tribunais')
        ->assertOk();
});

it('rejeita requisição sem token', function () {
    $this->getJson('/api/tribunais')
        ->assertStatus(401)
        ->assertJson(['message' => 'Token inválido ou não fornecido']);
});

it('rejeita token desconhecido', function () {
    criarTokenApi('mni_token_valido');

    $this->withHeaders(['X-API-Token' => 'mni_token_errado'])
        ->getJson('/api/tribunais')
        ->assertStatus(401)
        ->assertJson(['message' => 'Token inválido ou não fornecido']);
});

it('rejeita token inativo', function () {
    criarTokenApi('mni_token_inativo')->update(['ativo' => false]);

    $this->withHeaders(['X-API-Token' => 'mni_token_inativo'])
        ->getJson('/api/tribunais')
        ->assertStatus(401);
});

it('rejeita token expirado', function () {
    criarTokenApi('mni_token_expirado')->update(['expires_at' => now()->subMinute()]);

    $this->withHeaders(['X-API-Token' => 'mni_token_expirado'])
        ->getJson('/api/tribunais')
        ->assertStatus(401);
});

it('não aceita mais o token do config/env', function () {
    config()->set('services.api.token', 'tk-env-antigo');

    $this->withHeaders(['X-API-Token' => 'tk-env-antigo'])
        ->getJson('/api/tribunais')
        ->assertStatus(401);
});

it('registra last_used_at ao usar o token', function () {
    $token = criarTokenApi('mni_token_uso');

    $this->withHeaders(['X-API-Token' => 'mni_token_uso'])
        ->getJson('/api/tribunais')
        ->assertOk();

    expect($token->fresh()->last_used_at)->not->toBeNull();
});

it('expõe o ApiToken resolvido nos attributes do request', function () {
    $token = criarTokenApi('mni_token_attributes');

    \Illuminate\Support\Facades\Route::get('/api/_teste-api-token', function (\Illuminate\Http\Request $request) {
        return response()->json(['api_token_id' => $request->attributes->get('apiToken')?->id]);
    })->middleware(\App\Http\Middleware\ValidateApiToken::class);

    $this->withHeaders(['X-API-Token' => 'mni_token_attributes'])
        ->getJson('/api/_teste-api-token')
        ->assertOk()
        ->assertJson(['api_token_id' => $token->id]);
});
