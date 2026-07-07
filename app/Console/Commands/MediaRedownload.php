<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ProcessoDocumento;
use App\Jobs\BaixarDocumentoMNIJob;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class MediaRedownload extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'media:redownload {--batch=50 : Número de documentos a processar por vez}';

    /**
     * The console command description.
     */
    protected $description = 'Remove do S3 e reenfileira para download documentos de vídeo e áudio';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $batchSize = (int) $this->option('batch');

        $this->info('Iniciando processo de remoção e redownload de documentos de mídia...');
        $this->line('');

        // Tipos de mídia para processar
        $mimetypes = ['video/mp4', 'video/quicktime', 'audio/mpeg'];

        // Contar total de documentos
        $totalCount = ProcessoDocumento::whereIn('mimetype', $mimetypes)
            ->where('status', ProcessoDocumento::STATUS_BAIXADO)
            ->whereNotNull('path')
            ->count();

        $this->info("Total de documentos para reprocessar: {$totalCount}");
        $this->info("Tamanho do lote: {$batchSize}");
        $this->info("Número estimado de lotes: " . ceil($totalCount / $batchSize));

        foreach ($mimetypes as $mimetype) {
            $count = ProcessoDocumento::where('mimetype', $mimetype)
                ->where('status', ProcessoDocumento::STATUS_BAIXADO)
                ->whereNotNull('path')
                ->count();
            $this->info("- {$mimetype}: {$count} documentos");
        }

        if ($totalCount === 0) {
            $this->info('Nenhum documento encontrado para reprocessar.');
            return 0;
        }

        $this->line('');

        if (!$this->confirm('Deseja continuar com a remoção e redownload destes documentos?')) {
            $this->info('Operação cancelada.');
            return 0;
        }

        $this->line('');
        $this->info('Processando documentos em lotes...');

        $totalRemovidos = 0;
        $totalErros = 0;
        $totalRequeued = 0;
        $batchNumber = 1;

        // Processar em lotes
        do {
            $this->info("Processando lote {$batchNumber}...");

            // Buscar próximo lote
            $documentos = ProcessoDocumento::whereIn('mimetype', $mimetypes)
                ->where('status', ProcessoDocumento::STATUS_BAIXADO)
                ->whereNotNull('path')
                ->limit($batchSize)
                ->get();

            if ($documentos->isEmpty()) {
                break;
            }

            $removidos = 0;
            $erros = 0;
            $requeued = 0;

            $bar = $this->output->createProgressBar($documentos->count());
            $bar->start();

            foreach ($documentos as $documento) {
                try {
                    // Remover arquivo do S3 se existir
                    if ($documento->path && Storage::disk('s3')->exists($documento->path)) {
                        if (Storage::disk('s3')->delete($documento->path)) {
                            $removidos++;
                        } else {
                            Log::warning("Falha ao remover arquivo do S3: {$documento->path}");
                        }
                    }

                    // Atualizar registro no banco
                    $documento->update([
                        'path' => null,
                        'status' => ProcessoDocumento::STATUS_PENDENTE
                    ]);

                    // Enfileirar para redownload
                    BaixarDocumentoMNIJob::dispatch($documento)->onQueue('mni-download');
                    $requeued++;
                } catch (\Exception $e) {
                    $erros++;
                    Log::error("Erro ao processar documento {$documento->id}: " . $e->getMessage());
                }

                $bar->advance();
            }

            $bar->finish();
            $this->line('');

            $totalRemovidos += $removidos;
            $totalErros += $erros;
            $totalRequeued += $requeued;

            $this->info("Lote {$batchNumber} concluído:");
            $this->info("  - Processados: {$documentos->count()}");
            $this->info("  - Removidos do S3: {$removidos}");
            $this->info("  - Enfileirados: {$requeued}");
            if ($erros > 0) {
                $this->error("  - Erros: {$erros}");
            }
            $this->line('');

            $batchNumber++;

            // Pequena pausa entre lotes para não sobrecarregar
            if ($documentos->count() == $batchSize) {
                sleep(1);
            }
        } while ($documentos->count() == $batchSize);

        $this->info('Processo de reprocessamento concluído!');
        $this->info("Total de lotes processados: " . ($batchNumber - 1));
        $this->info("Total de arquivos removidos do S3: {$totalRemovidos}");
        $this->info("Total de documentos enfileirados: {$totalRequeued}");

        if ($totalErros > 0) {
            $this->error("Total de erros: {$totalErros}");
            $this->line('Verifique os logs para mais detalhes.');
        }

        return $totalErros > 0 ? 1 : 0;
    }
}
