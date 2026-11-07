<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RetornoWorkflowOcrRequest;
use App\Models\ProcessoDocumento;
use App\Services\Ocr\ProcessamentoOcrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Retorno do workflow "Jurídico OCR (Gemini)" no `webhook_url` (RF-12,
 * CT-04). A credencial já foi conferida por `ValidarAssinaturaWebhookOcr`,
 * e o `documento` da query vem dela. As regras de cada retorno ficam em
 * `ProcessamentoOcrService::aplicarRetorno`.
 *
 * O corpo nunca vai para o log: só o documento, o `status` e o `job_id`.
 */
class OcrWebhookController extends Controller
{
    public function __construct(private readonly ProcessamentoOcrService $processamento) {}

    public function store(RetornoWorkflowOcrRequest $request): JsonResponse
    {
        $jobId = $request->jobId();
        $status = $request->validated('status');
        $documentoId = filter_var($request->query('documento'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        // Só o job atual do documento é conhecido: o job antigo de um
        // documento devolvido à fila ou cancelado recebe 404 (RF-12, R2-2).
        $documento = $documentoId === false
            ? null
            : ProcessoDocumento::query()
                ->whereKey($documentoId)
                ->where('ocr_job_id', $jobId)
                ->first();

        if ($documento === null) {
            return $this->jobDesconhecido($documentoId, $status, $jobId);
        }

        $resultado = $this->processamento->aplicarRetorno($documento, $request->sucesso(), $request->detalheDoErro());

        // O documento foi devolvido à fila ou reenviado entre a busca acima e
        // a trava do service: o job deixou de ser conhecido, e nada mudou.
        if ($documento->ocr_job_id !== $jobId) {
            return $this->jobDesconhecido($documentoId, $status, $jobId);
        }

        Log::info("[ProcessoDocumento:{$documento->id}] retorno do workflow", [
            'status' => $status,
            'job_id' => $jobId,
            'resultado' => $resultado->value,
        ]);

        return response()->json(['message' => 'OK']);
    }

    private function jobDesconhecido(int|false $documentoId, string $status, string $jobId): JsonResponse
    {
        $documento = $documentoId === false ? '?' : $documentoId;

        Log::info("[ProcessoDocumento:{$documento}] retorno do workflow com job desconhecido", [
            'status' => $status,
            'job_id' => $jobId,
        ]);

        return response()->json(['message' => 'Job não encontrado'], 404);
    }
}
