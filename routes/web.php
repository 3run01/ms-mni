<?php

use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProcessoController;
use App\Http\Controllers\TribunalController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Rotas públicas
Route::get('/', function () {
    return redirect()->route('login');
});

// Documentação da API (OpenAPI/Redoc) — pública
Route::get('/docs/api', function () {
    return view('docs.api');
})->name('docs.api');

Route::get('/docs/api/openapi.yaml', function () {
    return response()->file(base_path('docs/api/openapi.yaml'), [
        'Content-Type' => 'application/yaml',
        'Cache-Control' => 'no-cache, must-revalidate',
    ]);
})->name('docs.api.spec');

// Rotas de autenticação
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth:web')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    Route::get('/tribunais', [TribunalController::class, 'index'])->name('tribunais.index');
    Route::get('/tribunais/criar', [TribunalController::class, 'create'])->name('tribunais.create');
    Route::post('/tribunais', [TribunalController::class, 'store'])->name('tribunais.store');
    Route::get('/tribunais/{tribunal}/editar', [TribunalController::class, 'edit'])->name('tribunais.edit');
    Route::put('/tribunais/{tribunal}', [TribunalController::class, 'update'])->name('tribunais.update');
    Route::patch('/tribunais/{tribunal}/ativo', [TribunalController::class, 'toggleAtivo'])->name('tribunais.toggle');

    Route::get('/tokens', [ApiTokenController::class, 'index'])->name('tokens.index');
    Route::get('/tokens/criar', [ApiTokenController::class, 'create'])->name('tokens.create');
    Route::post('/tokens', [ApiTokenController::class, 'store'])->name('tokens.store');
    Route::patch('/tokens/{apiToken}/ativo', [ApiTokenController::class, 'toggleAtivo'])->name('tokens.toggle');
    Route::delete('/tokens/{apiToken}', [ApiTokenController::class, 'destroy'])->name('tokens.destroy');

    Route::get('/processos', [ProcessoController::class, 'index'])->name('processos.index');
    Route::get('/processos/{processo}', [ProcessoController::class, 'show'])->name('processos.show');
});
