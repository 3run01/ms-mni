<?php

namespace App\Jobs;

use App\Models\ProcessoExportacao;
use App\Models\Tribunal;
use App\Services\Exportacao\ExportacaoProcessoService;
use App\Services\Processo\ProcessoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GerarPdfExportacaoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 600;

    public function __construct(public int $exportacaoId) {}

    public function handle(ExportacaoProcessoService $service): void
    {
        $exportacao = ProcessoExportacao::find($this->exportacaoId);

        if (!$exportacao) {
            Log::warning("[Exportacao:{$this->exportacaoId}] não encontrada ao gerar PDF");
            return;
        }

        try {
            $exportacao->update(['status' => ProcessoExportacao::STATUS_PROCESSANDO]);

            $this->consultarWebservice($exportacao);

            $documentos = $service->consultarDocumentos($exportacao);

            if ($documentos->isEmpty()) {
                $service->marcarComoFalhou($exportacao, 'Nenhum documento encontrado para os filtros informados.');
                return;
            }

            $service->gerarPdf($exportacao, $documentos);

            EnviarParaS3ExportacaoJob::dispatch($exportacao->id)->onQueue('exportar-processo');
            Log::info("[Exportacao:{$exportacao->id}] PDF gerado, enviando para S3");
        } catch (\Throwable $e) {
            Log::error("[Exportacao:{$exportacao->id}] erro na geração do PDF", [
                'erro' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $service->marcarComoFalhou($exportacao, $e->getMessage() ?: 'Erro ao gerar PDF.');
        }
    }

    private function consultarWebservice(ProcessoExportacao $exportacao): void
    {
        if (!$exportacao->tribunal_id) {
            return;
        }

        try {
            $tribunal = Tribunal::find($exportacao->tribunal_id);
            if ($tribunal) {
                (new ProcessoService())->consultarNumero($tribunal, $exportacao->numero_processo);
            }
        } catch (\Throwable $e) {
            Log::warning("[Exportacao:{$exportacao->id}] falha ao consultar webservice (best-effort): {$e->getMessage()}");
        }
    }
}
