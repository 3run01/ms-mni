<?php

namespace App\Jobs;

use App\Models\ProcessoDocumento;
use App\Models\ProcessoOcrProcessamento;
use App\Services\Ocr\DecisaoReenvioOcr;
use App\Services\Ocr\ProcessamentoOcrService;
use App\Services\Ocr\VerificarJobAntigoOcrService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Consulta o job antigo de um documento aguardando retorno com idade ≥ A e
 * decide o reenvio (D9; RF-18 item 3, R5-1, R6-1 a R6-3, R7-1). Despachado
 * na abertura de um processamento (T19) e em cada rodada do acompanhamento
 * com o processamento aberto, depois da avaliação de P (T18).
 *
 * Com `Reenviar`, devolve o documento à fila (o job antigo deixa de ser
 * conhecido pelo webhook, RF-12) e despacha EnviarDocumentoAoOcrJob, que
 * cancela o job antigo (CT-07) antes do novo `POST`. Com `Aguardar` ou
 * `Processado`, não reenvia.
 *
 * Sem o par do PJe: leva só os ids e não é cifrado (RNF-01). Fica fora do
 * pedido HTTP, para que N consultas não somem ao tempo do 202 (RNF-03).
 */
class VerificarReenvioOcrJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Uma tentativa só, de propósito: a consulta que falha (tempo esgotado,
     * 5xx, falha de rede) não é repetida pela fila. A nova consulta é a da
     * rodada seguinte do acompanhamento, que roda a cada minuto (R6-2).
     */
    public int $tries = 1;

    public int $timeout = 60;

    /**
     * Uma consulta por documento entre rodadas: a rodada seguinte não
     * acumula outra enquanto esta ainda está na fila ou rodando.
     */
    public int $uniqueFor = 120;

    public function __construct(
        public int $processamentoId,
        public int $documentoId,
    ) {
        $this->onQueue(EnviarDocumentoAoOcrJob::FILA);
    }

    public function uniqueId(): string
    {
        return (string) $this->documentoId;
    }

    public function handle(VerificarJobAntigoOcrService $verificador, ProcessamentoOcrService $processamentos): void
    {
        $prefixo = "[ProcessamentoOcr:{$this->processamentoId}]";

        // 1. Processamento encerrado: sai sem consultar. O documento que
        //    segue aguardando retorno fica para a abertura seguinte (LIM-02).
        $processamento = ProcessoOcrProcessamento::find($this->processamentoId);

        if ($processamento?->situacao !== ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO) {
            Log::info("{$prefixo} consulta antes do reenvio do documento {$this->documentoId} ignorada: processamento encerrado");

            return;
        }

        $documento = ProcessoDocumento::find($this->documentoId);

        if ($documento === null || (int) $documento->processo_id !== (int) $processamento->processo_id) {
            Log::warning("{$prefixo} documento {$this->documentoId} não pertence ao processo do processamento; consulta ignorada");

            return;
        }

        // 2. Só o documento aguardando retorno com idade ≥ A. O retorno pode
        //    ter chegado pelo webhook enquanto o job esperava na fila.
        if ($documento->ocr_situacao !== ProcessoDocumento::OCR_ENVIADO || ! $this->atingiuIdadeDeReenvio($documento)) {
            return;
        }

        // 3. Consulta o job antigo (CT-05) e decide.
        $decisao = $verificador->verificar($documento);

        if ($decisao !== DecisaoReenvioOcr::Reenviar) {
            return;
        }

        // `false` quando o documento mudou desde a leitura (o retorno chegou
        // ou outro worker já o devolveu): nada a reenviar.
        if (! $processamentos->devolverParaFila($documento)) {
            Log::info("[ProcessoDocumento:{$documento->id}] reenvio ao OCR desistido: o documento mudou durante a consulta");

            return;
        }

        Log::info("{$prefixo} documento {$documento->id} devolvido à fila para o reenvio ao OCR", [
            'job_id_cancelar' => $documento->ocr_job_id_cancelar,
        ]);

        EnviarDocumentoAoOcrJob::dispatch($this->processamentoId, $documento->id)
            ->onQueue(EnviarDocumentoAoOcrJob::FILA);
    }

    /**
     * Idade conta do 202 do workflow (`ocr_enviado_em`, RF-18 item 1). Sem a
     * data, a idade é desconhecida, e a consulta segue: é ela que decide.
     */
    private function atingiuIdadeDeReenvio(ProcessoDocumento $documento): bool
    {
        $enviadoEm = $documento->ocr_enviado_em;

        if ($enviadoEm === null) {
            return true;
        }

        $idadeDeReenvio = (int) config('services.n8n_ocr.idade_maxima_sem_retorno_minutos');

        return $enviadoEm->lte(now()->subMinutes($idadeDeReenvio));
    }
}
