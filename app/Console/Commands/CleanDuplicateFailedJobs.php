<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanDuplicateFailedJobs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:clean-duplicate-failed-jobs {--dry-run : Simular a limpeza sem deletar}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove registros duplicados de failed_jobs (mesmos UUIDs) mantendo apenas o mais recente';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');

        $this->info('Analisando registros duplicados em failed_jobs...');

        // Encontrar UUIDs duplicados
        $duplicates = DB::table('failed_jobs')
            ->select('uuid', DB::raw('COUNT(*) as count'), DB::raw('MAX(id) as max_id'))
            ->groupBy('uuid')
            ->having(DB::raw('COUNT(*)'), '>', 1)
            ->get();

        if ($duplicates->isEmpty()) {
            $this->info('✓ Nenhum registro duplicado encontrado.');
            return 0;
        }

        $this->warn("⚠ Encontrados " . $duplicates->count() . " UUIDs duplicados:");

        $totalDeleted = 0;

        foreach ($duplicates as $duplicate) {
            $count = $duplicate->count;
            $maxId = $duplicate->max_id;
            $uuid = $duplicate->uuid;

            $this->line("  UUID: {$uuid} - {$count} registros (mantendo ID: {$maxId})");

            if (!$dryRun) {
                // Deletar todos exceto o mais recente (max_id)
                $deleted = DB::table('failed_jobs')
                    ->where('uuid', $uuid)
                    ->where('id', '!=', $maxId)
                    ->delete();

                $totalDeleted += $deleted;
            }
        }

        if ($dryRun) {
            $this->warn("\n[DRY RUN] Seriam deletados aproximadamente " . ($duplicates->sum('count') - $duplicates->count()) . " registros");
            $this->info("Execute sem --dry-run para deletar os registros duplicados");
        } else {
            $this->info("\n✓ Limpeza concluída!");
            $this->info("Registros deletados: {$totalDeleted}");
        }

        return 0;
    }
}
