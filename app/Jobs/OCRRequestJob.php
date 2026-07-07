<?php

namespace App\Jobs;

use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\ProcessoMovimento;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class OCRRequestJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public ProcessoDocumento $documento;
    public int $tries = 5;
    public int $timeout = 60;
    public int $uniqueFor = 1800;
    public array $backoff = [60, 120, 300, 600];

    public function __construct(ProcessoDocumento $documento)
    {
        $this->documento = $documento;
    }

    public function uniqueId(): string
    {
        return (string) $this->documento->id;
    }

    public function handle(): void
    {
        $this->documento->refresh();

        if ($this->documento->ocr_processado) {
            return;
        }

        // ocr_job_id preenchido = microserviço já recebeu — aguarda webhook/poll
        if ($this->documento->ocr_job_id !== null) {
            return;
        }

        $mimetypesPermitidos = (array) config('services.sim_ocr.mimetypes_permitidos', []);
        if ($this->documento->mimetype && ! in_array($this->documento->mimetype, $mimetypesPermitidos, true)) {
            Log::info('[OCR] Documento ignorado por mimetype nao permitido', [
                'documento_id' => $this->documento->id_documento,
                'mimetype'     => $this->documento->mimetype,
            ]);

            $this->documento->ocr_processado = true;
            $this->documento->ocr_enviado_fila = true;
            $this->documento->ocr_concluido_data = now();
            $this->documento->save();

            return;
        }

        $this->documento->ocr_enviado_fila = true;
        $this->documento->save();

        $processo = $this->documento->processo;
        if (!$processo) {
            throw new \RuntimeException('Documento ' . $this->documento->id_documento . ' sem processo associado.');
        }

        $pathDestino = 'documentos-processos/'
            . $processo->numero_processo
            . '/' . $this->documento->id_documento . '.txt';

        $webhookUrl = config('services.sim_ocr.webhook_url');

        if (empty($webhookUrl)) {
            Log::warning('[OCR] SIM_OCR_WEBHOOK_URL nao configurada — sim-ocr nao tera para onde notificar conclusao', [
                'documento_id' => $this->documento->id_documento,
            ]);
        }

        $response = Http::timeout(30)
            ->withHeaders([
                'Authorization' => 'Bearer ' . config('services.sim_ocr.token'),
            ])
            ->post(config('services.sim_ocr.url') . '/documents/process', [
                'bucket_origem'  => config('services.sim_ocr.bucket_origem'),
                'path_origem'    => $this->documento->path,
                'bucket_destino' => config('services.sim_ocr.bucket_destino'),
                'path_destino'   => $pathDestino,
                'webhook_url'    => config('services.sim_ocr.webhook_url'),
                'metadata'       => [
                    'numero_processo'  => $processo->numero_processo,
                    'numero_documento' => (string) $this->documento->id_documento,
                    'tipo_documento'             => $this->documento->tipo_documento,
                    'descricao_tipo_documento'   => $this->documento->getTipoDocumento()?->descricao,
                    'descricao'                  => $this->documento->descricao,
                    'movimento'             => $this->documento->movimento,
                    'descricao_movimento'   => ProcessoMovimento::where('processo_id', $this->documento->processo_id)
                        ->where('identificador_movimento', $this->documento->movimento)
                        ->value('complemento'),
                    'mimetype'         => $this->documento->mimetype,
                    'data_juntada'     => $this->documento->data_juntada,
                ],
            ]);

        if (!$response->successful()) {
            Log::error('Falha ao enviar documento ao microserviço OCR', [
                'documento_id' => $this->documento->id_documento,
                'status'       => $response->status(),
                'body'         => $response->body(),
            ]);
            throw new \RuntimeException('Microserviço OCR retornou HTTP ' . $response->status());
        }

        $this->documento->ocr_job_id = $response->json('id');
        $this->documento->save();

        $this->salvarMetadataJson($processo, $pathDestino);
    }

    private function salvarMetadataJson(Processo $processo, string $pathDestino): void
    {
        $metadata = [
            'metadataAttributes' => [
                'descricao-documento' => $this->documento->descricao,
                'mimetype'            => $this->documento->mimetype,
                'tipo-documento'      => $this->documento->getTipoDocumento()?->descricao,
                'numero-processo'     => $processo->numero_processo,
                'nome-orgao-julgador' => $processo->nome_orgao_julgador,
                'classe'              => $processo->classe?->descricao,
                'assunto'             => $processo->assuntos()->first()?->nome,
                'tribunal'            => $processo->tribunal?->nome,
                'versao-ocr'          => config('app.versao_ocr'),
                'created-at'          => now()->format('Y-m-d H:i:s'),
            ],
        ];

        Storage::disk('s3_ocr')->put(
            $pathDestino . '.metadata.json',
            json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'private'
        );
    }

    public function failed(\Throwable $e): void
    {
        // Se já tem job_id, o microserviço recebeu — não resetar, aguardar webhook/poll
        if ($this->documento->ocr_job_id !== null) {
            return;
        }

        $this->documento->ocr_enviado_fila = false;
        $this->documento->save();
    }
}
