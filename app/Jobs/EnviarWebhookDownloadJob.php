<?php

namespace App\Jobs;

use App\Models\ProcessoExportacao;
use App\Services\Exportacao\WebhookDownloadClient;
use App\Services\Exportacao\WebhookPermanentException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class EnviarWebhookDownloadJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $exportacaoId) {}

    public function backoff(): array
    {
        return [10, 60, 300, 900, 3600];
    }

    public function handle(WebhookDownloadClient $client): void
    {
        $exportacao = \DB::transaction(function () {
            $exportacao = ProcessoExportacao::query()
                ->lockForUpdate()
                ->find($this->exportacaoId);

            if (!$exportacao) {
                return null;
            }

            if ($exportacao->jaFoiNotificado()) {
                return false; // sentinel: idempotency hit
            }

            $exportacao->increment('webhook_tentativas');
            return $exportacao->fresh();
        });

        if ($exportacao === null) {
            Log::warning("[Exportacao:{$this->exportacaoId}] não encontrada ao enviar webhook");
            return;
        }

        if ($exportacao === false) {
            return;
        }

        try {
            $client->notificar($exportacao);
        } catch (WebhookPermanentException $e) {
            Log::critical("[Exportacao:{$exportacao->id}] webhook rejeitado pelo SIM (permanente)", [
                'status' => $e->statusCode,
                'mensagem' => $e->getMessage(),
            ]);
            $this->fail($e);
            return;
        }

        // At-least-once delivery: webhook_enviado_em é setado APÓS resposta 2xx do SIM,
        // mas o processo pode morrer entre a resposta e este update. Nesse caso o retry
        // re-POSTa. SIM endpoint deve ser idempotente do lado dele (cf. contrato).
        $exportacao->update(['webhook_enviado_em' => now()]);
        Log::info("[Exportacao:{$exportacao->id}] webhook enviado");
    }

    public function failed(\Throwable $e): void
    {
        Log::critical("[Exportacao:{$this->exportacaoId}] esgotou tentativas de webhook", [
            'erro' => $e->getMessage(),
        ]);
    }
}
