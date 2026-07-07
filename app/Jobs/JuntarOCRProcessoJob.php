<?php

namespace App\Jobs;

use App\Models\Processo;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Aws\S3\S3Client;
use App\Jobs\SyncBaseConhecimentoSamiaJob;

class JuntarOCRProcessoJob implements ShouldQueue
{
    use Queueable;

    public $processo;

    /**
     * Número de tentativas do job
     */
    public $tries = 3;

    /**
     * Timeout em segundos (10 minutos)
     */
    public $timeout = 600;

    /**
     * Create a new job instance.
     */
    public function __construct(Processo $processo)
    {
        $this->processo = $processo;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startTime = microtime(true);

        try {
            Log::info("Iniciando junção de arquivos OCR", [
                'processo' => $this->processo->numero_processo,
                'processo_id' => $this->processo->id
            ]);

            // 1. Buscar todos os documentos com OCR processado deste processo
            $documentos = $this->processo->documentos()
                ->where('ocr_processado', true)
                ->orderBy('data_hora', 'asc')
                ->get();

            if ($documentos->isEmpty()) {
                Log::warning("Nenhum documento com OCR processado encontrado", [
                    'processo' => $this->processo->numero_processo
                ]);
                return;
            }

            Log::info("Documentos encontrados para junção", [
                'processo' => $this->processo->numero_processo,
                'total_documentos' => $documentos->count()
            ]);

            // 2. Ler e concatenar todos os arquivos de texto
            $textoCompleto = '';
            $documentosProcessados = 0;

            foreach ($documentos as $documento) {
                $caminhoArquivo = "documentos-processos/{$this->processo->numero_processo}/{$documento->id_documento}.txt";

                try {
                    if (Storage::disk('s3_ocr')->exists($caminhoArquivo)) {
                        $conteudo = Storage::disk('s3_ocr')->get($caminhoArquivo);

                        // Adicionar cabeçalho com informações do documento
                        $textoCompleto .= "\n" . str_repeat("=", 80) . "\n";
                        $textoCompleto .= "DOCUMENTO: {$documento->id_documento}\n";
                        $textoCompleto .= "TIPO: {$documento->descricao}\n";
                        $textoCompleto .= "DATA: {$documento->data_hora}\n";
                        $textoCompleto .= str_repeat("=", 80) . "\n\n";

                        // Adicionar o conteúdo do documento
                        $textoCompleto .= $conteudo . "\n\n";

                        $documentosProcessados++;
                    } else {
                        Log::warning("Arquivo OCR não encontrado no bucket", [
                            'processo' => $this->processo->numero_processo,
                            'documento_id' => $documento->id_documento,
                            'caminho' => $caminhoArquivo
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error("Erro ao ler arquivo OCR do documento", [
                        'processo' => $this->processo->numero_processo,
                        'documento_id' => $documento->id_documento,
                        'erro' => $e->getMessage()
                    ]);
                    // Continuar com os próximos documentos mesmo se houver erro
                    continue;
                }
            }

            if (empty($textoCompleto)) {
                Log::warning("Nenhum conteúdo para juntar", [
                    'processo' => $this->processo->numero_processo
                ]);
                return;
            }

            // 3. Salvar arquivo consolidado no S3
            $caminhoArquivoProcesso = "documentos-processos/{$this->processo->numero_processo}/processo.txt";

            // Criar cliente S3
            $s3Client = new S3Client([
                'version' => 'latest',
                'region' => env('AWS_DEFAULT_REGION'),
                'credentials' => [
                    'key' => env('AWS_ACCESS_KEY_ID_OCR'),
                    'secret' => env('AWS_SECRET_ACCESS_KEY_OCR'),
                ],
                'endpoint' => env('AWS_ENDPOINT'),
                'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            ]);

            $bucket = env('AWS_BUCKET_OCR');

            // Preparar metadados do arquivo consolidado
            $metadata = [
                'numero-processo' => $this->processo->numero_processo,
                'total-documentos' => (string) $documentosProcessados,
                'versao-ocr' => env('VERSAO_OCR', '1.0'),
                'created-at' => date('Y-m-d H:i:s'),
                'tipo' => 'processo-completo'
            ];

            // Adicionar informações do tribunal se existir
            if ($this->processo->tribunal) {
                $metadata['tribunal'] = substr($this->processo->tribunal->nome, 0, 100);
            }

            // Salvar arquivo consolidado
            $s3Client->putObject([
                'Bucket' => $bucket,
                'Key' => $caminhoArquivoProcesso,
                'Body' => $textoCompleto,
                'ContentType' => 'text/plain; charset=utf-8',
                'Metadata' => $metadata
            ]);

            $duration = microtime(true) - $startTime;

            Log::info("✓ Arquivos OCR juntados com sucesso", [
                'processo' => $this->processo->numero_processo,
                'total_documentos' => $documentos->count(),
                'documentos_processados' => $documentosProcessados,
                'tamanho_bytes' => strlen($textoCompleto),
                'caminho_arquivo' => $caminhoArquivoProcesso,
                'duration_seconds' => round($duration, 2),
                'status' => 'SUCCESS'
            ]);

            // 4. Atualizar status do processo e disparar sincronização com a base de conhecimento
            $this->dispararSincronizacaoBaseConhecimento();
        } catch (\Exception $e) {
            $duration = microtime(true) - $startTime;

            Log::error("✗ Erro ao juntar arquivos OCR do processo", [
                'processo' => $this->processo->numero_processo,
                'processo_id' => $this->processo->id,
                'erro' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'duration_seconds' => round($duration, 2),
                'status' => 'FAILED'
            ]);

            throw $e;
        }
    }

    /**
     * Atualiza o status do processo e dispara a sincronização com a base de conhecimento
     */
    private function dispararSincronizacaoBaseConhecimento(): void
    {
        try {
            // Atualizar o status do processo para STARTING (indicando que está pronto para sincronizar)
            $this->processo->update([
                'knowledge_base_status_sync' => Processo::KNOWLEDGE_BASE_STATUS_STARTING
            ]);

            Log::info("Status do processo atualizado para sincronização", [
                'processo' => $this->processo->numero_processo,
                'status' => Processo::KNOWLEDGE_BASE_STATUS_STARTING
            ]);

            // Disparar o job de sincronização com a base de conhecimento
            // SyncBaseConhecimentoSamiaJob::dispatch()->onQueue('default'); // desativado temporariamente

            Log::info("Job de sincronização com base de conhecimento disparado", [
                'processo' => $this->processo->numero_processo
            ]);
        } catch (\Exception $e) {
            Log::error("Erro ao disparar sincronização com base de conhecimento", [
                'processo' => $this->processo->numero_processo,
                'erro' => $e->getMessage()
            ]);
            // Não lançar exceção para não falhar o job de junção
        }
    }

    /**
     * Callback executado quando o job falha definitivamente
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical("Job de junção de OCR falhou definitivamente", [
            'processo' => $this->processo->numero_processo,
            'processo_id' => $this->processo->id,
            'erro' => $exception->getMessage(),
            'exception_class' => get_class($exception),
            'trace' => $exception->getTraceAsString()
        ]);

        // Marcar processo para reprocessamento se necessário
        $this->processo->update([
            'knowledge_base_status_sync' => Processo::KNOWLEDGE_BASE_STATUS_PENDING
        ]);
    }
}
