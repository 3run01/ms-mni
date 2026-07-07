<?php

namespace App\Console\Commands;

use App\Jobs\EnviarWebhookDownloadJob;
use App\Models\ProcessoExportacao;
use Illuminate\Console\Command;

class ExportacoesReenviarWebhook extends Command
{
    protected $signature = 'exportacoes:reenviar-webhook {exportacao_id?} {--reset-tentativas} {--force : Pula confirmação interativa (para uso em scripts)}';

    protected $description = 'Redespacha o webhook de uma (ou várias) exportações pendentes para o SIM';

    public function handle(): int
    {
        $id = $this->argument('exportacao_id');
        $reset = (bool) $this->option('reset-tentativas');

        if ($id) {
            return $this->reenviarUma((int) $id, $reset);
        }

        return $this->reenviarPendentes($reset);
    }

    private function reenviarUma(int $id, bool $reset): int
    {
        $exportacao = ProcessoExportacao::find($id);

        if (!$exportacao) {
            $this->error("Exportação {$id} não encontrada.");
            return self::FAILURE;
        }

        if ($reset) {
            $exportacao->update(['webhook_tentativas' => 0]);
        }

        EnviarWebhookDownloadJob::dispatch($exportacao->id)->onQueue('exportar-processo');
        $this->info("Webhook redespachado para exportação {$exportacao->id}.");
        return self::SUCCESS;
    }

    private function reenviarPendentes(bool $reset): int
    {
        $pendentes = ProcessoExportacao::query()
            ->whereIn('status', [ProcessoExportacao::STATUS_CONCLUIDO, ProcessoExportacao::STATUS_FALHOU])
            ->whereNull('webhook_enviado_em')
            ->where('created_at', '<=', now()->subHour())
            ->get();

        $total = $pendentes->count();

        if ($total === 0) {
            $this->info('Nenhuma exportação pendente de webhook há mais de 1 hora.');
            return self::SUCCESS;
        }

        $this->table(['id', 'user_id', 'status', 'created_at'], $pendentes->map(fn ($e) => [
            $e->id, $e->user_id, $e->status, $e->created_at->toDateTimeString(),
        ]));

        if (!$this->option('force') && !$this->confirm("Redespachar webhook para {$total} exportação(ões)?", true)) {
            return self::SUCCESS;
        }

        foreach ($pendentes as $e) {
            if ($reset) {
                $e->update(['webhook_tentativas' => 0]);
            }
            EnviarWebhookDownloadJob::dispatch($e->id)->onQueue('exportar-processo');
        }

        $this->info("Redespachado para {$total} exportação(ões).");
        return self::SUCCESS;
    }
}
