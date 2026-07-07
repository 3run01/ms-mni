<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\OCRProcessoJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OCRProcessoController extends Controller
{
    public function store(Request $request)
    {
        try {
            Log::info('OCRProcessoController: store', ['request' => $request->all()]);

            $validated = $request->validate([
                'numero_processo' => 'required|string',
                'tribunal_id' => 'required|integer',
                'notificacao_id' => 'nullable|integer',
                'login_pje' => 'nullable|string',
                'senha_pje' => 'nullable|string',
            ]);

            OCRProcessoJob::dispatch(
                cleanNumeroProcesso($validated['numero_processo']),
                (int) $validated['tribunal_id'],
                isset($validated['notificacao_id']) ? (int) $validated['notificacao_id'] : null,
                $validated['login_pje'] ?? null,
                $validated['senha_pje'] ?? null,
            )->onQueue('alta');

            return response()->json([
                'message' => 'Processo enfileirado para OCR.',
            ], 202);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Requisicao invalida.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('[OCR] Erro ao enfileirar OCRProcessoJob', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Erro ao processar requisicao de OCR.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
