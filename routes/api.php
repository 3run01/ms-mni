<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\ConsultarProcessoController;
use App\Http\Controllers\Api\DocumentoController;
use App\Http\Controllers\Api\DownloadProcessoController;
use App\Http\Controllers\Api\MonitoramentoProcessoController;
use App\Http\Controllers\Api\TribunalController;
use App\Http\Middleware\InjectCredenciaisPjePadrao;
use App\Http\Middleware\ValidateApiToken;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware([ValidateApiToken::class, InjectCredenciaisPjePadrao::class])->group(function () {
    Route::get('/processo/consultar', [ConsultarProcessoController::class, 'index']);
    Route::get('/processo/visualizar', [ConsultarProcessoController::class, 'show']);

    Route::get('/documento/visualizar', [DocumentoController::class, 'show']);
    Route::resource('/tribunais', TribunalController::class)->only(['index', 'show']);
    Route::post('/processo/download', [DownloadProcessoController::class, 'store']);

    //consultar dados basicos, movimentos e documentos
    Route::get('/processo/dados-basicos', [ConsultarProcessoController::class, 'consultarDadosBasicos']);
    Route::get('/processo/movimentos/listar', [ConsultarProcessoController::class, 'consultarMovimentos']);
    Route::get('/processo/documentos/listar', [DocumentoController::class, 'listarDocumentos']);

    Route::group(['prefix' => '/processo/consultar'], function () {
        Route::get('/dados-basicos/async', [ConsultarProcessoController::class, 'consultarDadosBasicosAsync']);
        Route::get('/movimentos/async', [ConsultarProcessoController::class, 'consultarMovimentosAsync']);
        Route::get('/documentos/async', [DocumentoController::class, 'consultarDocumentosAsync']);
    });
});

// Monitoramento fica fora do grupo acima de propósito: sem
// InjectCredenciaisPjePadrao, senão o par padrão do .env seria persistido como
// cópia cifrada congelada e a rotação do .env não propagaria. Sem credencial no
// payload, o job lê o config fresco a cada execução.
Route::middleware([ValidateApiToken::class])->group(function () {
    Route::group(['prefix' => '/processo/monitoramentos'], function () {
        Route::post('/', [MonitoramentoProcessoController::class, 'store']);
        Route::get('/', [MonitoramentoProcessoController::class, 'index']);
        Route::get('/{uuid}', [MonitoramentoProcessoController::class, 'show']);
        Route::patch('/{uuid}', [MonitoramentoProcessoController::class, 'update']);
        Route::delete('/{uuid}', [MonitoramentoProcessoController::class, 'destroy']);
        Route::get('/{uuid}/execucoes', [MonitoramentoProcessoController::class, 'execucoes']);
        Route::post('/{uuid}/executar', [MonitoramentoProcessoController::class, 'executar']);
    });
});
