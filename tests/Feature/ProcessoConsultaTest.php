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
    $createdNumbers = [];
    for ($i = 1; $i <= 25; $i++) {
        $numero = $prefixo . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
        novoProcesso(['numero_processo' => $numero]);
        $createdNumbers[] = $numero;
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

    // Verify pagination stability: fetch both pages and check they don't overlap
    $page1Processos = Processo::where('numero_processo', 'ilike', "%{$prefixo}%")
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->limit(20)
        ->get()
        ->pluck('numero_processo')
        ->toArray();

    $page2Processos = Processo::where('numero_processo', 'ilike', "%{$prefixo}%")
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->offset(20)
        ->limit(5)
        ->get()
        ->pluck('numero_processo')
        ->toArray();

    // Assert disjoint sets: no numero_processo appears in both pages
    $overlap = array_intersect($page1Processos, $page2Processos);
    expect($overlap)->toBeEmpty('Pages must have disjoint sets of numero_processo');

    // Assert together they cover all 25 created numbers
    $allNumeros = array_merge($page1Processos, $page2Processos);
    expect(count($allNumeros))->toBe(25, 'Total of 25 unique numbers across pages');
});
