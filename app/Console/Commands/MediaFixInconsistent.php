<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ProcessoDocumento;
use App\Jobs\BaixarDocumentoMNIJob;
use Illuminate\Support\Facades\Log;

class MediaFixInconsistent extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'media:fix-inconsistent';

    /**
     * The console command description.
     */
    protected $description = 'Corrige documentos marcados como baixados mas sem path definido';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Corrigindo documentos inconsistentes (baixados sem path)...');
        $this->line('');

        // Tipos de mídia para processar
        $mimetypes = ['video/mp4', 'video/quicktime', 'audio/mpeg'];

        // Buscar documentos inconsistentes
        $documentos = ProcessoDocumento::whereIn('mimetype', $mimetypes)
            ->where('status', ProcessoDocumento::STATUS_BAIXADO)
            ->whereNull('path')
            ->get();

        $total = $documentos->count();

        if ($total === 0) {
            $this->info('Nenhum documento inconsistente encontrado.');
            return 0;
        }

        $this->info("Encontrados {$total} documentos inconsistentes:");

        foreach ($mimetypes as $mimetype) {
            $count = $documentos->where('mimetype', $mimetype)->count();
            if ($count > 0) {
                $this->info("- {$mimetype}: {$count} documentos");
            }
        }

        $this->line('');

        if (!$this->confirm('Deseja corrigir o status destes documentos para PENDENTE?')) {
            $this->info('Operação cancelada.');
            return 0;
        }

        $this->line('');
        $this->info('Corrigindo documentos...');

        $corrigidos = 0;
        $enfileirados = 0;
        $erros = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($documentos as $documento) {
            try {
                // Atualizar status para PENDENTE
                $documento->update([
                    'status' => ProcessoDocumento::STATUS_PENDENTE
                ]);
                $corrigidos++;

                // Enfileirar para download
                BaixarDocumentoMNIJob::dispatch($documento)->onQueue('mni-download');
                $enfileirados++;
            } catch (\Exception $e) {
                $erros++;
                Log::error("Erro ao corrigir documento {$documento->id}: " . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->line('');
        $this->line('');

        $this->info('Correção concluída!');
        $this->info("Total processado: {$total}");
        $this->info("Documentos corrigidos: {$corrigidos}");
        $this->info("Documentos enfileirados: {$enfileirados}");

        if ($erros > 0) {
            $this->error("Erros encontrados: {$erros}");
            $this->line('Verifique os logs para mais detalhes.');
        }

        return $erros > 0 ? 1 : 0;
    }
}
