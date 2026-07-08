<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CleanTempFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:clean-temp {--hours=24 : Arquivos mais antigos que X horas}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove arquivos temporários antigos da pasta storage/app/temp';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $tempDir = storage_path('app/temp');

        // Verificar se a pasta existe
        if (!file_exists($tempDir)) {
            $this->info('Pasta temp não existe. Nada para limpar.');
            return 0;
        }

        $hours = (int) $this->option('hours');
        $cutoffTime = now()->subHours($hours)->timestamp;

        $files = File::allFiles($tempDir);
        $deletedCount = 0;
        $totalSize = 0;

        foreach ($files as $file) {
            $filePath = $file->getRealPath();
            $fileTime = filemtime($filePath);

            if ($fileTime < $cutoffTime) {
                $fileSize = filesize($filePath);
                $totalSize += $fileSize;

                if (unlink($filePath)) {
                    $deletedCount++;
                }
            }
        }

        $totalSizeMB = round($totalSize / 1024 / 1024, 2);

        $this->info("Limpeza concluída!");
        $this->info("Arquivos removidos: {$deletedCount}");
        $this->info("Espaço liberado: {$totalSizeMB} MB");

        return 0;
    }
}
