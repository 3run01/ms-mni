<?php

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

uses(DatabaseTransactions::class);

function usuarioPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Usuário de Teste',
        'email' => 'usuario.teste.crud@example.com',
        'password' => 'senha-segura-123',
        'password_confirmation' => 'senha-segura-123',
    ], $overrides);
}

function administradorLogado(): User
{
    return User::factory()->create();
}

it('redireciona visitante para o login', function () {
    $this->get('/usuarios')->assertRedirect('/login');
    $this->post('/usuarios', usuarioPayload())->assertRedirect('/login');

    expect(User::where('email', 'usuario.teste.crud@example.com')->exists())->toBeFalse();
});

it('lista usuários no componente usuarios/index sem expor a senha', function () {
    $listado = User::factory()->create(['email' => 'listado.crud@example.com']);

    $this->actingAs(administradorLogado())
        ->get('/usuarios')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('usuarios/index')
            ->where('usuarios', fn ($usuarios) => collect($usuarios)->contains('email', $listado->email))
            ->missing('usuarios.0.password')
            ->missing('usuarios.0.remember_token'));
});

it('renderiza o formulário de criação', function () {
    $this->actingAs(administradorLogado())
        ->get('/usuarios/criar')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('usuarios/create'));
});

it('cria usuário com senha em hash e redireciona com flash', function () {
    $this->actingAs(administradorLogado())
        ->post('/usuarios', usuarioPayload())
        ->assertRedirect(route('usuarios.index'))
        ->assertSessionHas('success');

    $usuario = User::where('email', 'usuario.teste.crud@example.com')->first();
    expect($usuario)->not->toBeNull();
    expect($usuario->name)->toBe('Usuário de Teste');
    expect($usuario->password)->not->toBe('senha-segura-123');
    expect(Hash::check('senha-segura-123', $usuario->password))->toBeTrue();
});

it('permite que o usuário criado faça login', function () {
    $this->actingAs(administradorLogado())
        ->post('/usuarios', usuarioPayload());

    auth()->logout();

    $this->post('/login', ['email' => 'usuario.teste.crud@example.com', 'password' => 'senha-segura-123'])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs(User::where('email', 'usuario.teste.crud@example.com')->first());
});

it('normaliza o e-mail para minúsculas e sem espaços', function () {
    $this->actingAs(administradorLogado())
        ->post('/usuarios', usuarioPayload(['email' => '  Usuario.Teste.CRUD@Example.com ']))
        ->assertRedirect(route('usuarios.index'));

    expect(User::where('email', 'usuario.teste.crud@example.com')->exists())->toBeTrue();
});

it('valida campos obrigatórios no store', function () {
    $this->actingAs(administradorLogado())
        ->post('/usuarios', [])
        ->assertInvalid(['name', 'email', 'password']);
});

it('rejeita e-mail inválido', function () {
    $this->actingAs(administradorLogado())
        ->post('/usuarios', usuarioPayload(['email' => 'nao-e-email']))
        ->assertInvalid(['email']);
});

it('rejeita e-mail já cadastrado, ignorando maiúsculas', function () {
    User::factory()->create(['email' => 'duplicado.crud@example.com']);

    $this->actingAs(administradorLogado())
        ->post('/usuarios', usuarioPayload(['email' => 'Duplicado.CRUD@example.com']))
        ->assertInvalid(['email' => 'Já existe um usuário com esse e-mail.']);
});

it('rejeita senha com menos de 8 caracteres', function () {
    $this->actingAs(administradorLogado())
        ->post('/usuarios', usuarioPayload(['password' => 'curta', 'password_confirmation' => 'curta']))
        ->assertInvalid(['password']);
});

it('rejeita confirmação de senha diferente', function () {
    $this->actingAs(administradorLogado())
        ->post('/usuarios', usuarioPayload(['password_confirmation' => 'outra-senha-123']))
        ->assertInvalid(['password' => 'A confirmação não confere com a senha.']);
});

it('renderiza o formulário de edição sem a senha', function () {
    $usuario = User::factory()->create();

    $this->actingAs(administradorLogado())
        ->get("/usuarios/{$usuario->id}/editar")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('usuarios/edit')
            ->where('usuario.id', $usuario->id)
            ->where('usuario.email', $usuario->email)
            ->missing('usuario.password'));
});

it('atualiza nome e e-mail mantendo a senha quando ela vem em branco', function () {
    $usuario = User::factory()->create(['password' => 'senha-original-1']);

    $this->actingAs(administradorLogado())
        ->put("/usuarios/{$usuario->id}", [
            'name' => 'Nome Atualizado',
            'email' => 'atualizado.crud@example.com',
            'password' => '',
            'password_confirmation' => '',
        ])
        ->assertRedirect(route('usuarios.index'));

    $usuario->refresh();
    expect($usuario->name)->toBe('Nome Atualizado');
    expect($usuario->email)->toBe('atualizado.crud@example.com');
    expect(Hash::check('senha-original-1', $usuario->password))->toBeTrue();
});

it('troca a senha quando uma nova é informada', function () {
    $usuario = User::factory()->create(['password' => 'senha-original-1']);

    $this->actingAs(administradorLogado())
        ->put("/usuarios/{$usuario->id}", usuarioPayload([
            'email' => $usuario->email,
            'password' => 'senha-nova-456',
            'password_confirmation' => 'senha-nova-456',
        ]))
        ->assertRedirect(route('usuarios.index'));

    expect(Hash::check('senha-nova-456', $usuario->fresh()->password))->toBeTrue();
});

it('permite manter o próprio e-mail na edição', function () {
    $usuario = User::factory()->create(['email' => 'mesmo.crud@example.com']);

    $this->actingAs(administradorLogado())
        ->put("/usuarios/{$usuario->id}", ['name' => 'Outro Nome', 'email' => 'mesmo.crud@example.com'])
        ->assertValid(['email']);
});

it('rejeita na edição o e-mail de outro usuário', function () {
    User::factory()->create(['email' => 'ocupado.crud@example.com']);
    $usuario = User::factory()->create();

    $this->actingAs(administradorLogado())
        ->put("/usuarios/{$usuario->id}", ['name' => 'Qualquer', 'email' => 'ocupado.crud@example.com'])
        ->assertInvalid(['email']);
});
