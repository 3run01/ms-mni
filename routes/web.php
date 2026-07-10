<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\TribunalController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Rotas públicas
Route::get('/', function () {
    return redirect()->route('login');
});

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
});
