<?php
// app/Http/Controllers/ApiTokenController.php

namespace App\Http\Controllers;

use App\Http\Requests\ApiTokenRequest;
use App\Models\ApiToken;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ApiTokenController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('tokens/index', [
            'tokens' => ApiToken::query()
                ->select(['id', 'name', 'ativo', 'expires_at', 'last_used_at', 'created_at'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('tokens/create');
    }

    public function store(ApiTokenRequest $request): RedirectResponse
    {
        $plainToken = ApiToken::generatePlainToken();

        ApiToken::create([
            'name' => $request->validated()['name'],
            'token' => ApiToken::hashToken($plainToken),
            'ativo' => true,
            'expires_at' => $request->validated()['expires_at'] ?? null,
        ]);

        return redirect()->route('tokens.index')
            ->with('success', 'Token criado.')
            ->with('token', $plainToken);
    }

    public function toggleAtivo(ApiToken $apiToken): RedirectResponse
    {
        $apiToken->update(['ativo' => ! $apiToken->ativo]);

        return back()->with('success', 'Token atualizado.');
    }

    public function destroy(ApiToken $apiToken): RedirectResponse
    {
        $apiToken->delete();

        return redirect()->route('tokens.index')->with('success', 'Token revogado.');
    }
}
