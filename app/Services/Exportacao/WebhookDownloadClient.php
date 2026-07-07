<?php

namespace App\Services\Exportacao;

use App\Models\ProcessoExportacao;
use Illuminate\Support\Facades\Http;

class WebhookDownloadClient
{
    public function notificar(ProcessoExportacao $exportacao): void
    {
        $payload = $this->montarPayload($exportacao);
        $url = (string) config('services.sim_webhook_download.url');
        $token = (string) config('services.sim_webhook_download.token');
        $timeout = (int) config('services.sim_webhook_download.timeout', 10);

        $response = Http::withHeaders(['X-API-Token' => $token])
            ->timeout($timeout)
            ->post($url, $payload);

        if ($response->successful()) {
            return;
        }

        // 4xx => erro permanente; 5xx => throw para o queue retentar
        if ($response->status() >= 400 && $response->status() < 500) {
            throw new WebhookPermanentException(
                $response->status(),
                "SIM rejeitou webhook (HTTP {$response->status()}): {$response->body()}"
            );
        }

        $response->throw();
    }

    private function montarPayload(ProcessoExportacao $exportacao): array
    {
        $base = [
            'user_id' => $exportacao->user_id,
            'titulo' => $exportacao->titulo,
            'formato' => $exportacao->formato,
            'status' => $exportacao->status,
        ];

        if ($exportacao->status === ProcessoExportacao::STATUS_CONCLUIDO) {
            return $base + [
                's3_path' => $exportacao->s3_path,
                'tamanho_bytes' => $exportacao->tamanho_bytes,
            ];
        }

        return $base + ['erro_resumo' => $exportacao->erro_resumo];
    }
}
