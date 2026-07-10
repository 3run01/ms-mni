<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('aplica classe dark no html quando cookie appearance=dark', function () {
    $this->withUnencryptedCookie('appearance', 'dark')
        ->get('/login')
        ->assertOk()
        ->assertSee('class="dark"', false);
});

it('não aplica classe dark sem cookie', function () {
    $this->get('/login')
        ->assertOk()
        ->assertDontSee('class="dark"', false);
});

it('compartilha sidebarOpen a partir do cookie sidebar_state', function () {
    $user = User::factory()->make(['id' => 1]);

    $this->actingAs($user)
        ->withUnencryptedCookie('sidebar_state', 'false')
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('sidebarOpen', false));
});
