<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A rota raiz redireciona para o login.
     */
    public function test_the_application_redirects_root_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }
}
