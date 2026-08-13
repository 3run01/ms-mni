<?php

namespace App\Console\Commands;

use App\Jobs\EnviarWebhookMonitoramentoJob;
use App\Models\ProcessoMonitoramentoExecucao;
use Illuminate\Console\Command;

class MonitoramentosReenviarWebhook extends Command
{
    protected $signature = 'monitoramentos:reenviar-webhook {execucao_id?} {--reset-tentativas} {--force : Pula confirmação interativa (para uso em scripts)}';

    protected $description = 'Redespacha o webhook de uma (ou várias) execuções de monitoramento pendentes';

    public function handle(): int
    {
        $id = $this->argument('execucao_id');
        $reset = (bool) $this->option('reset-tentativas');

        if ($id) {
            return $this->reenviarUma((int) $id, $reset);
        }

        return $this->reenviarPendentes($reset);
    }

    private function reenviarUma(int $id, bool $reset): int
    {
        $execucao = ProcessoMonitoramentoExecucao::find($id);

        if (! $execucao) {
            $this->error("Execução {$id} não encontrada.");
            return self::FAILURE;
        }

        if ($reset) {
            $execucao->update(['webhook_tentativas' => 0]);
        }

        EnviarWebhookMonitoramentoJob::dispatch($execucao->id)->onQueue('monitoramento-webhook');
        $this->info("Webhook redespachado para execução {$execucao->id}.");

        return self::SUCCESS;
    }

    private function reenviarPendentes(bool $reset): int
    {
        $pendentes = ProcessoMonitoramentoExecucao::query()
            ->whereNull('webhook_enviado_em')
            ->where('created_at', '<=', now()->subHour())
            ->get();

        $total = $pendentes->count();

        if ($total === 0) {
            $this->info('Nenhuma execução pendente de webhook há mais de 1 hora.');
            return self::SUCCESS;
        }

        $this->table(['id', 'monitoramento_id', 'status', 'created_at'], $pendentes->map(fn ($e) => [
            $e->id, $e->monitoramento_id, $e->status, $e->created_at->toDateTimeString(),
        ]));

        if (! $this->option('force') && ! $this->confirm("Redespachar webhook para {$total} execução(ões)?", true)) {
            return self::SUCCESS;
        }

        foreach ($pendentes as $execucao) {
            if ($reset) {
                $execucao->update(['webhook_tentativas' => 0]);
            }

            EnviarWebhookMonitoramentoJob::dispatch($execucao->id)->onQueue('monitoramento-webhook');
        }

        $this->info("Redespachado para {$total} execução(ões).");

        return self::SUCCESS;
    }
}
