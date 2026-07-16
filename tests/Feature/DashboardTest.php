<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

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
