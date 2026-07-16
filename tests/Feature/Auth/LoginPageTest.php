<?php

use Inertia\Testing\AssertableInertia as Assert;

it('renderiza a página de login via Inertia', function () {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/login'));
});

it('valida campos obrigatórios no login', function () {
    $this->from('/login')
        ->post('/login', [])
        ->assertRedirect('/login')
        ->assertInvalid(['email', 'password']);
});
