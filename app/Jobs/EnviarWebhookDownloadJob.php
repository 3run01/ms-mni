<?php

namespace App\Jobs;

use App\Models\ProcessoExportacao;
use App\Services\Callback\CallbackNotifier;
use App\Services\Callback\CallbackPermanentException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class EnviarWebhookDownloadJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $exportacaoId) {}

    public function backoff(): array
    {
        return [10, 60, 300, 900, 3600];
    }

    public function handle(CallbackNotifier $notifier): void
    {
        $exportacao = \DB::transaction(function () {
            $exportacao = ProcessoExportacao::query()->lockForUpdate()->find($this->exportacaoId);

            if (! $exportacao) {
                return null;
            }
            if ($exportacao->jaFoiNotificado()) {
                return false;
            }

            $exportacao->increment('webhook_tentativas');
            return $exportacao->fresh();
        });

        if ($exportacao === null) {
            Log::warning("[Exportacao:{$this->exportacaoId}] não encontrada ao enviar callback");
            return;
        }
        if ($exportacao === false) {
            return;
        }

        try {
            $notifier->notificar($exportacao->callback_url, $exportacao->callback_token, $this->montarPayload($exportacao));
        } catch (CallbackPermanentException $e) {
            Log::critical("[Exportacao:{$exportacao->id}] callback rejeitado (permanente)", [
                'status' => $e->statusCode,
                'mensagem' => $e->getMessage(),
            ]);
            $this->fail($e);
            return;
        }

        $exportacao->update(['webhook_enviado_em' => now()]);
        Log::info("[Exportacao:{$exportacao->id}] callback enviado");
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
                'download_url' => Storage::disk('s3')->temporaryUrl($exportacao->s3_path, now()->addMinutes(60)),
                'tamanho_bytes' => $exportacao->tamanho_bytes,
            ];
        }

        return $base + ['erro_resumo' => $exportacao->erro_resumo];
    }

    public function failed(\Throwable $e): void
    {
        Log::critical("[Exportacao:{$this->exportacaoId}] esgotou tentativas de callback", [
            'erro' => $e->getMessage(),
        ]);
    }
}
