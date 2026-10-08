<?php

namespace App\Jobs;

use App\Exceptions\MNIException;
use App\Models\ProcessoDocumento;
use App\Services\Processo\BaixarDocumentosEmLoteService;
use App\Services\Processo\SalvarDocumentoProcessoService;
use App\Services\Processo\TravaDownloadDocumento;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Download em lote dos binários não-vídeo de um processo, na fila
 * `mni-download`.
 *
 * Sem ShouldBeUnique: quem impede o mesmo documento em dois lotes é a
 * TravaDownloadDocumento, adquirida no despacho por documento. O job só a
 * libera ao terminar: sem exceção em handle() ou na falha definitiva.
 */
class BaixarDocumentosMNIEmLoteJob implements ShouldQueue
{
    use Queueable;

    // Alinhados ao supervisor-mni-download (config/horizon.php).
    public int $tries = 3;
    public int $timeout = 300;

    /**
     * @param  array<int, int>  $processoDocumentoIds  ids de `processo_documentos`, não `id_documento`
     * @param  array<int, int>  $tentativasNoDespacho  `tentativas_download` de cada id no despacho; na
     *                                                 falha definitiva separa quem já recebeu +1 neste job
     */
    public function __construct(
        public int $processoId,
        public array $processoDocumentoIds,
        public array $tentativasNoDespacho,
        public string $donoTrava,
        private ?string $login_pje = null,
        private ?string $senha_pje = null,
    ) {}

    public function handle(): void
    {
        app(BaixarDocumentosEmLoteService::class)->executar(
            $this->processoId,
            $this->processoDocumentoIds,
            $this->login_pje,
            $this->senha_pje,
        );

        // Fora de finally de propósito: depois de uma falha total a trava
        // segura o lote entre as tentativas da fila, até o failed().
        app(TravaDownloadDocumento::class)->liberarTodas($this->processoDocumentoIds, $this->donoTrava);
    }

    /**
     * Falha definitiva (tentativas esgotadas, inclusive por timeout do worker
     * ou falta de memória): `erro` em todo documento do lote que não esteja
     * `baixado` com o objeto no S3 e liberação das travas.
     */
    public function failed(?\Throwable $e): void
    {
        try {
            $this->marcarPendentesComoErro($e);
        } finally {
            app(TravaDownloadDocumento::class)->liberarTodas($this->processoDocumentoIds, $this->donoTrava);
        }
    }

    private function marcarPendentesComoErro(?\Throwable $e): void
    {
        $salvarDocumento = app(SalvarDocumentoProcessoService::class);
        $motivo = 'Falha definitiva do download em lote: ' . $this->mensagemDaFalha($e);
        $marcados = 0;

        $documentos = ProcessoDocumento::where('processo_id', $this->processoId)
            ->whereIn('id', $this->processoDocumentoIds)
            ->get();

        foreach ($documentos as $documento) {
            if ($this->baixadoNoS3($documento)) {
                continue;
            }

            // +1 só para quem não recebeu +1 em nenhuma tentativa deste job:
            // enquanto a trava vale, só este job mexe no contador.
            $tentativasNoDespacho = (int) ($this->tentativasNoDespacho[$documento->id] ?? $documento->tentativas_download);
            $incrementar = (int) $documento->tentativas_download <= $tentativasNoDespacho;

            $salvarDocumento->marcarComoErro($documento, $motivo, $incrementar);
            $marcados++;
        }

        Log::warning("[Processo:{$this->processoId}] lote com falha definitiva", [
            'processo_documento_ids' => $this->processoDocumentoIds,
            'marcados_erro' => $marcados,
            'motivo' => $motivo,
        ]);
    }

    private function baixadoNoS3(ProcessoDocumento $documento): bool
    {
        if ($documento->status !== ProcessoDocumento::STATUS_BAIXADO || empty($documento->path)) {
            return false;
        }

        try {
            return Storage::disk('s3')->exists($documento->path);
        } catch (\Throwable $e) {
            // Sem confirmar o objeto, o documento segue elegível: melhor um
            // `erro` recuperável que um `baixado` sem arquivo.
            Log::warning("[ProcessoDocumento:{$documento->id}] falha ao verificar o objeto no S3; tratado como ausente", [
                'erro' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function mensagemDaFalha(?\Throwable $e): string
    {
        if ($e instanceof MNIException) {
            return $e->getError();
        }

        return $e?->getMessage() ?: 'motivo não informado pela fila';
    }
}
