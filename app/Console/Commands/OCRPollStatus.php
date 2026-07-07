<?php

namespace App\Console\Commands;

use App\Jobs\JuntarOCRProcessoJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OCRPollStatus extends Command
{
    protected $signature = 'ocr:poll-status
                            {--processo= : Número do processo (opcional, consulta todos se omitido)}
                            {--limit=100 : Máximo de documentos a consultar por execução}';

    protected $description = 'Consulta o status dos jobs de OCR pendentes no microserviço (substitui o webhook em dev)';

    public function handle(): int
    {
        $query = ProcessoDocumento::whereNotNull('ocr_job_id')
            ->where('ocr_processado', false)
            ->where('ocr_enviado_fila', true);

        if ($numeroProcesso = $this->option('processo')) {
            $processo = Processo::where('numero_processo', cleanNumeroProcesso($numeroProcesso))->first();

            if (!$processo) {
                $this->error("Processo não encontrado: {$numeroProcesso}");
                return self::FAILURE;
            }

            $query->where('processo_id', $processo->id);
        }

        $documentos = $query->limit((int) $this->option('limit'))->get();

        if ($documentos->isEmpty()) {
            $this->info('Nenhum documento com OCR pendente.');
            return self::SUCCESS;
        }

        $this->info("Consultando {$documentos->count()} documento(s)...");

        $sucesso = 0;
        $falha   = 0;
        $pendente = 0;
        $processosParaNotificar = [];

        foreach ($documentos as $documento) {
            try {
                $response = Http::timeout(10)
                    ->withHeaders(['Authorization' => 'Bearer ' . config('services.sim_ocr.token')])
                    ->get(config('services.sim_ocr.url') . '/jobs/' . $documento->ocr_job_id);
            } catch (\Exception $e) {
                $this->warn("  [{$documento->id_documento}] Erro de conexão ao consultar sim-ocr — ignorando: {$e->getMessage()}");
                continue;
            }

            if ($response->status() === 404) {
                $this->warn("  [{$documento->id_documento}] job_id {$documento->ocr_job_id} não encontrado no microserviço — resetando para reenvio.");
                $documento->ocr_enviado_fila = false;
                $documento->ocr_job_id       = null;
                $documento->save();
                $falha++;
                continue;
            }

            if (!$response->successful()) {
                $this->error("  [{$documento->id_documento}] Erro HTTP {$response->status()} ao consultar job — ignorando.");
                continue;
            }

            $status = $response->json('status');

            match ($status) {
                'success' => $this->processarSucesso($documento, $sucesso, $processosParaNotificar),
                'failed'  => $this->processarFalha($documento, $response->json('error'), $falha),
                default   => $this->exibirPendente($documento, $status, $response->json('progress', 0), $pendente),
            };
        }

        foreach ($processosParaNotificar as $processo) {
            $this->notificarProgressoSim($processo);
        }

        $this->newLine();
        $this->table(
            ['Resultado', 'Total'],
            [['Sucesso', $sucesso], ['Falha/Resetado', $falha], ['Pendente', $pendente]]
        );

        return self::SUCCESS;
    }

    private function processarSucesso(ProcessoDocumento $documento, int &$sucesso, array &$processosParaNotificar): void
    {
        $documento->ocr_processado     = true;
        $documento->ocr_concluido_data = now();
        $documento->save();

        $processo = $documento->processo;
        $this->dispararJuncaoSeCompleto($processo);

        if ($processo && !isset($processosParaNotificar[$processo->numero_processo])) {
            $processosParaNotificar[$processo->numero_processo] = $processo;
        }

        $this->line("  <info>[{$documento->id_documento}]</info> success ✓");
        $sucesso++;
    }

    private function processarFalha(ProcessoDocumento $documento, ?string $erro, int &$falha): void
    {
        Log::error('OCR falhou no microserviço (polling)', [
            'job_id'       => $documento->ocr_job_id,
            'documento_id' => $documento->id_documento,
            'error'        => $erro,
        ]);

        $documento->ocr_enviado_fila = false;
        $documento->ocr_job_id       = null;
        $documento->save();

        $this->line("  <error>[{$documento->id_documento}]</error> failed — resetado para reenvio. Erro: {$erro}");
        $falha++;
    }

    private function exibirPendente(ProcessoDocumento $documento, string $status, int $progress, int &$pendente): void
    {
        $this->line("  [{$documento->id_documento}] {$status} ({$progress}%)");
        $pendente++;
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
                Log::warning('Falha ao notificar progresso OCR para sim via polling', [
                    'processo' => $processo->numero_processo,
                    'status'   => $response->status(),
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Falha ao notificar progresso OCR para sim via polling', [
                'processo' => $processo->numero_processo,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    private function dispararJuncaoSeCompleto(?Processo $processo): void
    {
        if (!$processo) {
            return;
        }

        $total = $processo->documentos()
            ->whereIn('mimetype', ['application/pdf', 'text/html'])
            ->where('status', ProcessoDocumento::STATUS_BAIXADO)
            ->count();

        $processados = $processo->documentos()
            ->whereIn('mimetype', ['application/pdf', 'text/html'])
            ->where('status', ProcessoDocumento::STATUS_BAIXADO)
            ->where('ocr_processado', true)
            ->count();

        if ($total > 0 && $processados >= $total) {
            $processo->refresh();

            if ($processo->knowledge_base_status_sync !== Processo::KNOWLEDGE_BASE_STATUS_STARTING) {
                return;
            }

            // JuntarOCRProcessoJob::dispatch($processo)->onQueue('ocr'); // desativado temporariamente (sem Samia)
        }
    }
}
