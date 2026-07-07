<?php

// use App\Http\Controllers\Api\ConsultarProcessoController;
// use App\Http\Controllers\Api\DocumentoController;
use App\Http\Controllers\Api\DownloadProcessoController;
use App\Http\Controllers\AuthController;
// use App\Http\Controllers\Api\TribunalController;
use Illuminate\Support\Facades\Route;

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
        return view('dashboard');
    })->name('dashboard');

    // Rotas protegidas existentes
    Route::get('/processo/download', [DownloadProcessoController::class, 'index']);
});

// Rotas comentadas para referência futura
// Route::post('/processo/download', [DownloadProcessoController::class, 'store']);
// Route::resource('/processo/consultar', ConsultarProcessoController::class);
// Route::get('/documento/{hash}/visualizar', [DocumentoController::class, 'show']);
// Route::resource('/tribunais', TribunalController::class)->only(['index', 'show']);
// Route::get('/processo-pje/consultar', [ConsultarProcessoController::class, 'consultarPje']);
