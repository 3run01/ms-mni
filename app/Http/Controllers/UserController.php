<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('usuarios/index', [
            'usuarios' => User::query()
                ->select(['id', 'name', 'email', 'created_at'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('usuarios/create');
    }

    public function store(UserRequest $request): RedirectResponse
    {
        User::create($request->validated());

        return redirect()->route('usuarios.index')->with('success', 'Usuário criado.');
    }

    public function edit(User $user): Response
    {
        return Inertia::render('usuarios/edit', [
            'usuario' => $user->only(['id', 'name', 'email']),
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $dados = $request->validated();

        if (blank($dados['password'] ?? null)) {
            unset($dados['password']);
        }

        $user->update($dados);

        return redirect()->route('usuarios.index')->with('success', 'Usuário atualizado.');
    }
}
