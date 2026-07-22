<?php

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;

uses(DatabaseTransactions::class);

it('renderiza o dashboard via Inertia com o usuário autenticado', function () {
    $user = User::factory()->make(['id' => 1]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('auth.user.name', $user->name)
            ->where('auth.user.email', $user->email));
});

it('redireciona visitante do dashboard para o login', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('entrega periodo padrão 30 e adia metricas', function () {
    $this->actingAs(User::factory()->make(['id' => 1]))
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('periodo', 30)
            ->missing('metricas'));
});

it('aceita periodo 7 e 90', function () {
    $user = User::factory()->make(['id' => 1]);

    $this->actingAs($user)
        ->get('/dashboard?periodo=7')
        ->assertInertia(fn (Assert $page) => $page->where('periodo', 7));

    $this->actingAs($user)
        ->get('/dashboard?periodo=90')
        ->assertInertia(fn (Assert $page) => $page->where('periodo', 90));
});

it('faz fallback para 30 com periodo inválido', function () {
    $this->actingAs(User::factory()->make(['id' => 1]))
        ->get('/dashboard?periodo=99')
        ->assertInertia(fn (Assert $page) => $page->where('periodo', 30));
});

it('entrega metricas no partial reload com o shape esperado', function () {
    $user = User::factory()->make(['id' => 1]);

    // primeiro carregamento: deferred props ausentes
    $this->actingAs($user)
        ->get('/dashboard?periodo=7')
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('periodo', 7)
            ->missing('metricas'));

    // partial reload (como o Inertia faz no cliente) entrega os dados
    $this->actingAs($user)
        ->get('/dashboard?periodo=7', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => \Inertia\Inertia::getVersion() ?? '',
            'X-Inertia-Partial-Component' => 'dashboard',
            'X-Inertia-Partial-Data' => 'metricas',
        ])
        ->assertOk()
        ->assertJsonStructure([
            'props' => [
                'metricas' => [
                    'totais' => ['processos', 'documentosBaixados', 'documentosPendentes', 'documentosErro'],
                    'processosPorDia',
                    'documentosPorDia',
                ],
            ],
        ])
        ->assertJsonCount(7, 'props.metricas.processosPorDia')
        ->assertJsonCount(7, 'props.metricas.documentosPorDia');
});
