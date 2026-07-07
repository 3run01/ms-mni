<?php

namespace App\Jobs;

use App\Models\Notificacao;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\Tribunal;
use App\Services\Processo\ProcessoService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Orquestra o pipeline de OCR de um processo:
 * - garante que o processo existe (cria via MNI se necessario)
 * - atrela a notificacao recebida do sim (se veio)
 * - garante que os documentos do processo existem (consulta MNI se necessario)
 * - marca o processo com ocr_status = PENDENTE (gate do OCR no BaixarDocumentoMNIJob)
 * - enfileira BaixarDocumentoMNIJob e OCRRequestJob conforme estado de cada documento
 * - atualiza knowledge_base_sequence_job
 *
 * Deixa o controller fino (apenas valida e despacha este job, respondendo 202).
 */
class OCRProcessoJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 300;
    public int $uniqueFor = 1800;
    public array $backoff = [60, 180, 600];

    public function __construct(
        public readonly string $numeroProcesso,
        public readonly int $tribunalId,
        public readonly ?int $notificacaoId = null,
        public readonly ?string $loginPje = null,
        public readonly ?string $senhaPje = null,
        /**
         * Quando true, este job nao faz fan-out para outras instancias do mesmo
         * codigo_tribunal. Usado pelos sub-jobs gerados pelo fan-out para evitar
         * recursao.
         */
        public readonly bool $skipFanout = false,
    ) {}

    public function uniqueId(): string
    {
        return $this->numeroProcesso . '-' . $this->tribunalId;
    }

    public function handle(ProcessoService $processoService): void
    {
        Log::info('[OCRProcessoJob] inicio', [
            'numero_processo' => $this->numeroProcesso,
            'tribunal_id' => $this->tribunalId,
            'skip_fanout' => $this->skipFanout,
        ]);

        $tribunal = Tribunal::find($this->tribunalId);
        if (!$tribunal) {
            Log::error('[OCRProcessoJob] Tribunal nao encontrado', ['tribunal_id' => $this->tribunalId]);
            return;
        }

        if (!$this->skipFanout) {
            $this->dispatchOutrasInstancias($tribunal);
        }

        $processo = $this->garantirProcesso($processoService, $tribunal);

        if (!$processo) {
            Log::info('[OCRProcessoJob] fim (processo nao existe nesta instancia)', [
                'numero_processo' => $this->numeroProcesso,
                'tribunal_id' => $this->tribunalId,
            ]);
            return;
        }

        $this->atrelarNotificacao($processo);
        $this->garantirDocumentos($processoService, $tribunal, $processo);
        $this->marcarOcrSolicitado($processo);
        $this->enfileirarDocumentos($processo);

        Log::info('[OCRProcessoJob] fim', [
            'numero_processo' => $this->numeroProcesso,
            'processo_id' => $processo->id,
        ]);
    }

    /**
     * Descobre outras instancias do mesmo tribunal CNJ (mesmo segmento_justica
     * E mesmo codigo_tribunal, mas tribunal_id diferente) e dispatcha um sub-job
     * para cada uma com skipFanout=true.
     *
     * Filtra por AMBOS os campos porque codigo_tribunal '03' pode aparecer em
     * justicas diferentes do mesmo estado — ex.: TJAP (J=8, TR=03), TRE-AP
     * (J=6, TR=03), TRT-AP (J=5, TR=03) sao tribunais distintos.
     *
     * A notificacao nao eh propagada (so atrela na instancia que recebeu o
     * pedido original).
     */
    private function dispatchOutrasInstancias(Tribunal $tribunal): void
    {
        $codigo = $tribunal->codigo_tribunal
            ?? extrairCodigoTribunal($this->numeroProcesso);

        $segmento = $tribunal->segmento_justica
            ?? extrairSegmentoJustica($this->numeroProcesso);

        if (!$codigo || !$segmento) {
            return;
        }

        $outras = Tribunal::where('codigo_tribunal', $codigo)
            ->where('segmento_justica', $segmento)
            ->where('id', '!=', $tribunal->id)
            ->where('ativo', true)
            ->pluck('id');

        if ($outras->isEmpty()) {
            return;
        }

        Log::info('[OCRProcessoJob] fan-out para outras instancias do tribunal', [
            'numero_processo' => $this->numeroProcesso,
            'codigo_tribunal' => $codigo,
            'segmento_justica' => $segmento,
            'tribunal_origem' => $tribunal->id,
            'outras_instancias' => $outras->all(),
        ]);

        foreach ($outras as $outroTribunalId) {
            self::dispatch(
                $this->numeroProcesso,
                $outroTribunalId,
                null,
                $this->loginPje,
                $this->senhaPje,
                true,
            )->onQueue('alta');
        }
    }

    private function garantirProcesso(ProcessoService $processoService, Tribunal $tribunal): ?Processo
    {
        $processo = Processo::where('numero_processo', $this->numeroProcesso)
            ->where('tribunal_id', $this->tribunalId)
            ->first();

        if ($processo) {
            return $processo;
        }

        Log::info('[OCRProcessoJob] Processo inexistente — consultando dados basicos via MNI', [
            'numero_processo' => $this->numeroProcesso,
            'tribunal_id' => $this->tribunalId,
        ]);

        try {
            return $processoService->consultarDadosBasicos(
                $tribunal,
                $this->numeroProcesso,
                $this->loginPje,
                $this->senhaPje,
            );
        } catch (\Throwable $e) {
            // Sub-job de fan-out: o processo pode legitimamente nao existir
            // nesta instancia (ex.: TJAP 1º Grau tem; 2º Grau nao). Tratamos
            // como ausencia esperada — sem retry, sem ERROR.
            if ($this->skipFanout) {
                Log::info('[OCRProcessoJob] Processo nao encontrado nesta instancia (fan-out) — ignorando', [
                    'numero_processo' => $this->numeroProcesso,
                    'tribunal_id' => $this->tribunalId,
                    'exception' => $e::class,
                    'erro' => $e->getMessage() ?: '(processo provavelmente nao existe nesta instancia)',
                ]);

                return null;
            }

            Log::error('[OCRProcessoJob] Falha ao consultar dados basicos no MNI', [
                'numero_processo' => $this->numeroProcesso,
                'tribunal_id' => $this->tribunalId,
                'exception' => $e::class,
                'erro' => $e->getMessage() ?: '(mensagem vazia — possivel processo nao encontrado, requer credenciais, ou sigilo)',
                'arquivo' => $e->getFile() . ':' . $e->getLine(),
            ]);

            throw $e;
        }
    }

    private function atrelarNotificacao(Processo $processo): void
    {
        if (!$this->notificacaoId) {
            return;
        }

        $processo->notificacoes()->firstOrCreate([
            'notificacao_id' => $this->notificacaoId,
            'tipo' => Notificacao::TIPO_OCR_PROCESSO,
        ]);
    }

    private function garantirDocumentos(ProcessoService $processoService, Tribunal $tribunal, Processo $processo): void
    {
        if ($processo->documentos()->count() > 0) {
            return;
        }

        Log::info('[OCRProcessoJob] Processo sem documentos — consultando MNI', [
            'processo_id' => $processo->id,
            'numero_processo' => $processo->numero_processo,
        ]);

        $processoService->consultarDocumentos(
            $tribunal,
            $processo->numero_processo,
            $this->loginPje,
            $this->senhaPje,
        );

        $processo->refresh();
    }

    private function marcarOcrSolicitado(Processo $processo): void
    {
        if (in_array($processo->ocr_status, [
            Processo::OCR_STATUS_PROCESSANDO,
            Processo::OCR_STATUS_CONCLUIDO,
        ], true)) {
            return;
        }

        $processo->ocr_status = Processo::OCR_STATUS_PENDENTE;
        $processo->save();
    }

    private function enfileirarDocumentos(Processo $processo): void
    {
        $codigosIgnorados = (array) config('services.sim_ocr.codigos_documentos_ignorados', []);
        $mimetypesPermitidos = (array) config('services.sim_ocr.mimetypes_permitidos', []);

        // Documentos ja baixados: enfileira OCRRequestJob diretamente.
        $documentosBaixados = $processo->documentos()
            ->where('ocr_processado', false)
            ->where('ocr_enviado_fila', false)
            ->where('status', ProcessoDocumento::STATUS_BAIXADO)
            ->whereNotIn('tipo_documento', $codigosIgnorados)
            ->whereIn('mimetype', $mimetypesPermitidos)
            ->get();

        foreach ($documentosBaixados as $documento) {
            $claimed = ProcessoDocumento::where('id', $documento->id)
                ->where('ocr_enviado_fila', false)
                ->update(['ocr_enviado_fila' => true]);

            if ($claimed) {
                OCRRequestJob::dispatch($documento)->onQueue('ocr-request');
            }
        }

        // Documentos pendentes: enfileira BaixarDocumentoMNIJob; o OCR sera disparado
        // dentro dele apos o download, gated por ocr_status do processo.
        $documentosPendentes = $processo->documentos()
            ->where('ocr_processado', false)
            ->where('ocr_enviado_fila', false)
            ->where('status', ProcessoDocumento::STATUS_PENDENTE)
            ->whereNotIn('tipo_documento', $codigosIgnorados)
            ->where(function ($q) use ($mimetypesPermitidos) {
                $q->whereNull('mimetype')
                  ->orWhereIn('mimetype', $mimetypesPermitidos);
            })
            ->get();

        foreach ($documentosPendentes as $documento) {
            BaixarDocumentoMNIJob::dispatch($documento)->onQueue('ocr-mni-download');
        }

        Log::info('[OCRProcessoJob] documentos enfileirados', [
            'processo_id' => $processo->id,
            'ocr_direto' => $documentosBaixados->count(),
            'downloads_pendentes' => $documentosPendentes->count(),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[OCRProcessoJob] falhou definitivamente', [
            'numero_processo' => $this->numeroProcesso,
            'tribunal_id' => $this->tribunalId,
            'erro' => $e->getMessage(),
            'arquivo' => $e->getFile() . ':' . $e->getLine(),
        ]);
    }
}
