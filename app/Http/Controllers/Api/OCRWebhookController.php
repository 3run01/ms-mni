<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\JuntarOCRProcessoJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OCRWebhookController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'job_id' => 'required|string',
            'status' => 'required|string|in:success,error',
        ]);

        $jobId  = $request->input('job_id');
        $status = $request->input('status');

        $documento = ProcessoDocumento::where('ocr_job_id', $jobId)->first();

        if (!$documento) {
            return response()->json(['message' => 'Job não encontrado.'], 404);
        }

        if ($status === 'success') {
            if ($documento->ocr_processado) {
                return response()->json(['message' => 'OK']);
            }

            $documento->ocr_processado     = true;
            $documento->ocr_concluido_data = now();
            $documento->save();

            $processo = $documento->processo;
            $this->dispararJuncaoSeCompleto($processo);
            $this->notificarProgressoSim($processo);

            return response()->json(['message' => 'OK']);
        }

        if ($status === 'error') {
            Log::error('OCR falhou no microserviço SIM OCR', [
                'job_id'       => $jobId,
                'documento_id' => $documento->id_documento,
                'error_detail' => $request->input('error_detail'),
            ]);

            $documento->ocr_enviado_fila = false;
            $documento->save();

            return response()->json(['message' => 'OK']);
        }

        return response()->json(['message' => 'OK']);
    }

    private function dispararJuncaoSeCompleto(?Processo $processo): void
    {
        if (!$processo) {
            return;
        }

        $totalDocumentos = $processo->documentos()
            ->whereIn('mimetype', ['application/pdf', 'text/html'])
            ->where('status', ProcessoDocumento::STATUS_BAIXADO)
            ->count();

        $documentosProcessados = $processo->documentos()
            ->whereIn('mimetype', ['application/pdf', 'text/html'])
            ->where('status', ProcessoDocumento::STATUS_BAIXADO)
            ->where('ocr_processado', true)
            ->count();

        if ($totalDocumentos > 0 && $documentosProcessados >= $totalDocumentos) {
            $processo->refresh();

            if ($processo->knowledge_base_status_sync !== Processo::KNOWLEDGE_BASE_STATUS_STARTING) {
                return;
            }

            // JuntarOCRProcessoJob::dispatch($processo)->onQueue('ocr'); // desativado temporariamente (sem Samia)
        }
    }

    private function notificarProgressoSim(?Processo $processo): void
    {
        if (!$processo) {
            return;
        }

        try {
            $response = Http::timeout(3)
                ->withHeaders(['X-API-Token' => config('services.sim_app.token')])
                ->post(config('services.sim_app.url') . '/webhook/ocr-progresso', [
                    'numero_processo' => $processo->numero_processo,
                ]);

            if ($response->failed()) {
                Log::warning('Falha ao notificar progresso OCR para sim', [
                    'processo' => $processo->numero_processo,
                    'status'   => $response->status(),
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Falha ao notificar progresso OCR para sim', [
                'processo' => $processo->numero_processo,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
