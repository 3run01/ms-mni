<?php

namespace App\Jobs;

use App\Models\ProcessoExportacao;
use App\Services\Exportacao\ExportacaoProcessoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class EnviarParaS3ExportacaoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 600;

    public function __construct(public int $exportacaoId) {}

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(ExportacaoProcessoService $service): void
    {
        $exportacao = ProcessoExportacao::find($this->exportacaoId);

        if (!$exportacao) {
            Log::warning("[Exportacao:{$this->exportacaoId}] não encontrada ao enviar para S3");
            return;
        }

        $caminhoLocal = storage_path("app/private/exportacoes/{$exportacao->uuid_arquivo}.pdf");

        if (!file_exists($caminhoLocal)) {
            throw new \RuntimeException("Arquivo local não encontrado: {$caminhoLocal}");
        }

        $service->enviarParaS3($exportacao, $caminhoLocal);
        Log::info("[Exportacao:{$exportacao->id}] enviado para S3", ['s3_path' => $exportacao->fresh()->s3_path]);

        EnviarWebhookDownloadJob::dispatch($exportacao->id)->onQueue('exportar-processo');
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[Exportacao:{$this->exportacaoId}] falha definitiva ao enviar para S3", [
            'erro' => $e->getMessage(),
        ]);

        $exportacao = ProcessoExportacao::find($this->exportacaoId);
        if ($exportacao) {
            app(ExportacaoProcessoService::class)
                ->marcarComoFalhou($exportacao, 'Falha ao enviar arquivo para o storage.');
        }
    }
}
