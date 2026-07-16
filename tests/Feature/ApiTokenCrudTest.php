<?php
// tests/Feature/ApiTokenCrudTest.php

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;

uses(DatabaseTransactions::class);

function usuarioLogado(): User
{
    return User::factory()->make(['id' => 1]);
}

it('redireciona visitante para o login', function () {
    $this->get('/tokens')->assertRedirect('/login');
});

it('lista tokens no componente tokens/index', function () {
    ApiToken::factory()->create(['name' => 'aaa-token-listado']);

    $this->actingAs(usuarioLogado())
        ->get('/tokens')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tokens/index')
            ->has('tokens')
            ->where('tokens.0.name', 'aaa-token-listado'));
});

it('renderiza o formulário de criação', function () {
    $this->actingAs(usuarioLogado())
        ->get('/tokens/criar')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tokens/create'));
});

it('cria token, persiste hash e flasha o plaintext uma única vez', function () {
    $response = $this->actingAs(usuarioLogado())
        ->post('/tokens', ['name' => 'teste 1', 'expires_at' => null]);

    $response->assertRedirect(route('tokens.index'))
        ->assertSessionHas('success')
        ->assertSessionHas('token');

    $plain = session('token');
    expect($plain)->toStartWith('mni_')->toHaveLength(52);

    $registro = ApiToken::where('name', 'teste 1')->first();
    expect($registro)->not->toBeNull();
    expect($registro->token)->toBe(ApiToken::hashToken($plain));
    expect($registro->ativo)->toBeTrue();
});

it('cria token com data de expiração', function () {
    $this->actingAs(usuarioLogado())
        ->post('/tokens', ['name' => 'token-expira', 'expires_at' => now()->addMonth()->format('Y-m-d')])
        ->assertRedirect(route('tokens.index'));

    expect(ApiToken::where('name', 'token-expira')->first()->expires_at)->not->toBeNull();
});

it('rejeita nome duplicado', function () {
    ApiToken::factory()->create(['name' => 'duplicado']);

    $this->actingAs(usuarioLogado())
        ->post('/tokens', ['name' => 'duplicado'])
        ->assertSessionHasErrors('name');
});

it('rejeita expiração no passado', function () {
    $this->actingAs(usuarioLogado())
        ->post('/tokens', ['name' => 'token-passado', 'expires_at' => now()->subDay()->format('Y-m-d')])
        ->assertSessionHasErrors('expires_at');
});

it('alterna ativo do token', function () {
    $token = ApiToken::factory()->create(['ativo' => true]);

    $this->actingAs(usuarioLogado())
        ->patch("/tokens/{$token->id}/ativo")
        ->assertRedirect();

    expect($token->fresh()->ativo)->toBeFalse();
});

it('revoga (exclui) token', function () {
    $token = ApiToken::factory()->create();

    $this->actingAs(usuarioLogado())
        ->delete("/tokens/{$token->id}")
        ->assertRedirect(route('tokens.index'));

    expect(ApiToken::find($token->id))->toBeNull();
});
