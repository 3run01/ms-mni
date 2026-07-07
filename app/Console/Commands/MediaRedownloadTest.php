<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ProcessoDocumento;
use App\Jobs\BaixarDocumentoMNIJob;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class MediaRedownloadTest extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'media:redownload-test {limit=5 : Número de documentos para testar}';

    /**
     * The console command description.
     */
    protected $description = 'Testa o reprocessamento com um número limitado de documentos';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = (int) $this->argument('limit');

        $this->info("Testando reprocessamento com {$limit} documentos...");
        $this->line('');

        // Tipos de mídia para processar
        $mimetypes = ['video/mp4', 'video/quicktime', 'audio/mpeg'];

        // Buscar documentos que atendem aos critérios (limitado)
        $documentos = ProcessoDocumento::whereIn('mimetype', $mimetypes)
            ->where('status', ProcessoDocumento::STATUS_BAIXADO)
            ->whereNotNull('path')
            ->limit($limit)
            ->get();

        $total = $documentos->count();
        $this->info("Selecionados {$total} documentos para teste:");

        foreach ($documentos as $documento) {
            $this->info("- ID: {$documento->id} | {$documento->mimetype} | {$documento->id_documento}");
        }

        if ($total === 0) {
            $this->info('Nenhum documento encontrado para testar.');
            return 0;
        }

        $this->line('');

        if (!$this->confirm('Deseja continuar com o teste de reprocessamento?')) {
            $this->info('Teste cancelado.');
            return 0;
        }

        $this->line('');
        $this->info('Processando documentos de teste...');

        $removidos = 0;
        $erros = 0;
        $requeued = 0;

        foreach ($documentos as $documento) {
            try {
                $this->info("Processando: {$documento->id_documento} ({$documento->mimetype})");

                // Remover arquivo do S3 se existir
                if ($documento->path && Storage::disk('s3')->exists($documento->path)) {
                    if (Storage::disk('s3')->delete($documento->path)) {
                        $removidos++;
                        $this->info("  ✅ Arquivo removido do S3: {$documento->path}");
                    } else {
                        $this->error("  ❌ Falha ao remover arquivo do S3: {$documento->path}");
                    }
                } else {
                    $this->comment("  ⚠️ Arquivo não encontrado no S3 ou path vazio");
                }

                // Atualizar registro no banco
                $documento->update([
                    'path' => null,
                    'status' => ProcessoDocumento::STATUS_PENDENTE
                ]);
                $this->info("  ✅ Status atualizado para PENDENTE");

                // Enfileirar para redownload
                BaixarDocumentoMNIJob::dispatch($documento)->onQueue('mni-download');
                $requeued++;
                $this->info("  ✅ Job enfileirado para redownload");
            } catch (\Exception $e) {
                $erros++;
                $this->error("  ❌ Erro: " . $e->getMessage());
                Log::error("Erro ao processar documento {$documento->id}: " . $e->getMessage());
            }

            $this->line('');
        }

        $this->info('Teste concluído!');
        $this->info("Total processado: {$total}");
        $this->info("Arquivos removidos do S3: {$removidos}");
        $this->info("Documentos enfileirados para redownload: {$requeued}");

        if ($erros > 0) {
            $this->error("Erros encontrados: {$erros}");
        }

        return $erros > 0 ? 1 : 0;
    }
}
