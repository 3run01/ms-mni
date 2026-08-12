<?php

namespace App\Console\Commands;

use App\Jobs\ExecutarMonitoramentoProcessoJob;
use App\Models\ProcessoMonitoramento;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MonitoramentosDespachar extends Command
{
    protected $signature = 'monitoramentos:despachar';

    protected $description = 'Enfileira na fila serial os monitoramentos vencidos (roda a cada 30 min)';

    private const CHUNK = 200;

    public function handle(): int
    {
        $despachados = 0;

        do {
            // O reagendamento acontece dentro da transação, junto do lock:
            // um segundo tick concorrente não enxerga os mesmos registros.
            $ids = DB::transaction(function () {
                $vencidos = ProcessoMonitoramento::query()
                    ->vencidos()
                    ->orderBy('proxima_execucao_em')
                    ->limit(self::CHUNK)
                    ->lock('for update skip locked')
                    ->get();

                foreach ($vencidos as $monitoramento) {
                    $monitoramento->update([
                        'bloqueado_ate' => now()->addMinutes((int) config('pje.monitoramento.bloqueio_despacho_minutos')),
                        'proxima_execucao_em' => now()->addHours($monitoramento->intervalo_horas),
                    ]);
                }

                return $vencidos->pluck('id');
            });

            foreach ($ids as $id) {
                ExecutarMonitoramentoProcessoJob::dispatch($id)->onQueue('monitoramento');
            }

            $despachados += $ids->count();
        } while ($ids->count() === self::CHUNK);

        $this->info("{$despachados} monitoramento(s) despachado(s).");
        Log::info("[Monitoramento] tick de despacho: {$despachados} enfileirado(s)");

        return self::SUCCESS;
    }
}
