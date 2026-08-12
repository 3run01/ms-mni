<?php

namespace App\Console\Commands;

use App\Models\ProcessoMonitoramentoExecucao;
use Illuminate\Console\Command;

class MonitoramentosLimparExecucoes extends Command
{
    protected $signature = 'monitoramentos:limpar-execucoes {--dias=90 : Idade mínima das execuções removidas}';

    protected $description = 'Remove o histórico de execuções de monitoramento mais antigo que N dias';

    public function handle(): int
    {
        $dias = (int) $this->option('dias');

        if ($dias < 1) {
            $this->error('--dias deve ser no mínimo 1.');
            return self::FAILURE;
        }

        $limite = now()->subDays($dias);
        $removidas = 0;

        do {
            $lote = ProcessoMonitoramentoExecucao::query()
                ->where('created_at', '<', $limite)
                ->limit(1000)
                ->delete();

            $removidas += $lote;
        } while ($lote > 0);

        $this->info("{$removidas} execução(ões) removida(s) com mais de {$dias} dia(s).");

        return self::SUCCESS;
    }
}
