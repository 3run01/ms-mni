<?php

use App\Models\Tribunal;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\SimDatabaseTestCase;

uses(SimDatabaseTestCase::class);

function tribunalPayload(array $overrides = []): array
{
    return array_merge([
        'nome' => 'Tribunal de Teste E2E',
        'tipo' => Tribunal::TIPO_STJ,
        'url_webservice_mni' => 'https://tribunal.test/mni',
        'url_webservice_mni_complementar' => 'https://tribunal.test/mni-complementar',
        'ativo' => true,
        'enviar_dados_criminais' => false,
    ], $overrides);
}

function autenticado(): User
{
    return User::factory()->make(['id' => 1]);
}

it('redireciona visitante para o login', function () {
    $this->get('/tribunais')->assertRedirect('/login');
});

it('lista tribunais no componente tribunais/index', function () {
    $tribunal = Tribunal::factory()->create(['nome' => 'AAA Tribunal Listado']);

    $this->actingAs(autenticado())
        ->get('/tribunais')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tribunais/index')
            ->has('tribunais')
            ->where('tribunais.0.nome', 'AAA Tribunal Listado'));
});

it('renderiza o formulário de criação com os tipos', function () {
    $this->actingAs(autenticado())
        ->get('/tribunais/criar')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tribunais/create')
            ->has('tipos', 5));
});

it('cria tribunal e redireciona com flash', function () {
    $this->actingAs(autenticado())
        ->post('/tribunais', tribunalPayload())
        ->assertRedirect(route('tribunais.index'))
        ->assertSessionHas('success');

    expect(Tribunal::where('nome', 'Tribunal de Teste E2E')->exists())->toBeTrue();
});

it('cria tribunal sem credenciais (colunas nullable)', function () {
    $this->actingAs(autenticado())
        ->post('/tribunais', tribunalPayload(['nome' => 'Tribunal Sem Credencial']))
        ->assertRedirect(route('tribunais.index'));

    $tribunal = Tribunal::where('nome', 'Tribunal Sem Credencial')->first();
    expect($tribunal)->not->toBeNull();
    expect($tribunal->login)->toBeNull();
    expect($tribunal->password)->toBeNull();
});

it('valida campos obrigatórios no store', function () {
    $this->actingAs(autenticado())
        ->post('/tribunais', [])
        ->assertInvalid(['nome', 'url_webservice_mni', 'url_webservice_mni_complementar']);
});

it('rejeita tipo fora da lista', function () {
    $this->actingAs(autenticado())
        ->post('/tribunais', tribunalPayload(['tipo' => 'Tribunal Inventado']))
        ->assertInvalid(['tipo']);
});

it('rejeita URL inválida', function () {
    $this->actingAs(autenticado())
        ->post('/tribunais', tribunalPayload(['url_webservice_mni' => 'nao-e-url']))
        ->assertInvalid(['url_webservice_mni']);
});

it('rejeita URL acima de 255 caracteres', function () {
    $urlLonga = 'https://tribunal.test/' . str_repeat('a', 250);

    $this->actingAs(autenticado())
        ->post('/tribunais', tribunalPayload(['url_webservice_mni' => $urlLonga]))
        ->assertInvalid(['url_webservice_mni']);
});

it('renderiza o formulário de edição sem a password', function () {
    $tribunal = Tribunal::factory()->create();

    $this->actingAs(autenticado())
        ->get("/tribunais/{$tribunal->id}/editar")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tribunais/edit')
            ->where('tribunal.id', $tribunal->id)
            ->missing('tribunal.password'));
});

it('atualiza tribunal', function () {
    $tribunal = Tribunal::factory()->create();

    $this->actingAs(autenticado())
        ->put("/tribunais/{$tribunal->id}", tribunalPayload(['nome' => 'Nome Atualizado']))
        ->assertRedirect(route('tribunais.index'));

    expect($tribunal->fresh()->nome)->toBe('Nome Atualizado');
});

it('inverte o ativo no toggle', function () {
    $tribunal = Tribunal::factory()->create(['ativo' => true]);

    $this->actingAs(autenticado())
        ->from('/tribunais')
        ->patch("/tribunais/{$tribunal->id}/ativo")
        ->assertRedirect('/tribunais');

    expect($tribunal->fresh()->ativo)->toBeFalse();
});
