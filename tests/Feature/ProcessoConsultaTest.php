<?php

use App\Models\Processo;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\MultiConnectionDatabaseTestCase;

uses(MultiConnectionDatabaseTestCase::class);

function loginProcessos(): User
{
    return User::factory()->make(['id' => 1]);
}

function novoProcesso(array $overrides = []): Processo
{
    return Processo::factory()->create($overrides);
}

it('redireciona visitante para o login na listagem', function () {
    $this->get('/processos')->assertRedirect('/login');
});

it('lista processos paginados no componente processos/index', function () {
    $prefixo = 'T1LISTA' . getmypid();
    novoProcesso(['numero_processo' => $prefixo . '001']);

    $this->actingAs(loginProcessos())
        ->get('/processos?busca=' . $prefixo)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('processos/index')
            ->has('processos.data', 1)
            ->where('processos.data.0.numero_processo', $prefixo . '001')
            ->has('processos.data.0.id')
            ->has('processos.total')
            ->has('processos.links'));
});

it('pagina de 20 em 20 preservando a query string', function () {
    $prefixo = 'T1PAG' . getmypid();
    for ($i = 1; $i <= 25; $i++) {
        novoProcesso(['numero_processo' => $prefixo . str_pad((string) $i, 3, '0', STR_PAD_LEFT)]);
    }

    $this->actingAs(loginProcessos())
        ->get('/processos?busca=' . $prefixo)
        ->assertInertia(fn (Assert $page) => $page
            ->has('processos.data', 20)
            ->where('processos.total', 25)
            ->where('processos.current_page', 1));

    $this->actingAs(loginProcessos())
        ->get('/processos?busca=' . $prefixo . '&page=2')
        ->assertInertia(fn (Assert $page) => $page
            ->has('processos.data', 5)
            ->where('processos.current_page', 2));
});
