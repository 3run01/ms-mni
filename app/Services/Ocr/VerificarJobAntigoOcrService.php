<?php

namespace App\Services\Ocr;

use App\Exceptions\FalhaTransitoriaWorkflowOcrException;
use App\Models\ProcessoDocumento;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Consulta do job antigo antes de reenviar um documento aguardando retorno
 * com idade ≥ A (D9; RF-18 item 3, R5-1, R6-1 a R6-3, R7-1), por
 * `GET jobs/{job_id}` (CT-05):
 *
 * | Resposta                                   | Decisão    |
 * |--------------------------------------------|------------|
 * | `success`                                  | Processado |
 * | 404, `failed` ou `cancelled`               | Reenviar   |
 * | `pending` ou `running` com idade < M       | Aguardar   |
 * | `pending` ou `running` com idade ≥ M       | Reenviar   |
 * | tempo esgotado, 5xx, rede, `status` fora   | Aguardar   |
 *
 * Só o `success` muda o documento aqui (processado, pela regra de RF-12).
 * `Reenviar` não marca erro nem devolve à fila: isso é de quem chama
 * (VerificarReenvioOcrJob). O fim por P (RF-13 item 1) tem regras próprias
 * e não passa por aqui.
 */
class VerificarJobAntigoOcrService
{
    public function __construct(
        private readonly ClienteWorkflowOcr $workflow,
        private readonly ProcessamentoOcrService $processamentos,
    ) {}

    /**
     * @throws InvalidArgumentException documento que não está aguardando
     *                                  retorno: quem chama confere antes
     */
    public function verificar(ProcessoDocumento $documento): DecisaoReenvioOcr
    {
        if ($documento->ocr_situacao !== ProcessoDocumento::OCR_ENVIADO) {
            throw new InvalidArgumentException("Documento {$documento->id} não está aguardando retorno do OCR");
        }

        $prefixo = "[ProcessoDocumento:{$documento->id}]";
        $jobId = (string) $documento->ocr_job_id;

        // Sem job não há o que consultar nem retorno a esperar: nenhum
        // webhook chegaria para ele.
        if (trim($jobId) === '') {
            Log::warning("{$prefixo} aguardando retorno do OCR sem job; reenvio sem consulta");

            return DecisaoReenvioOcr::Reenviar;
        }

        try {
            $situacao = $this->workflow->consultarJob($jobId);
        } catch (FalhaTransitoriaWorkflowOcrException $e) {
            // R6-2: sem reenvio nesta rodada; a seguinte consulta de novo.
            Log::warning("{$prefixo} consulta do job falhou", array_filter([
                'job_id' => $jobId,
                'status' => $e->statusHttp,
            ], fn ($valor) => $valor !== null));

            return DecisaoReenvioOcr::Aguardar;
        }

        $decisao = match ($situacao) {
            SituacaoJobOcr::Sucesso => $this->processado($documento),
            SituacaoJobOcr::Inexistente,
            SituacaoJobOcr::Falhou,
            SituacaoJobOcr::Cancelado => DecisaoReenvioOcr::Reenviar,
            SituacaoJobOcr::Pendente,
            SituacaoJobOcr::EmExecucao => $this->atingiuIdadeMaxima($documento)
                ? DecisaoReenvioOcr::Reenviar
                : DecisaoReenvioOcr::Aguardar,
        };

        Log::info("{$prefixo} job antigo do OCR consultado antes do reenvio", [
            'job_id' => $jobId,
            'situacao' => $situacao->name,
            'decisao' => $decisao->value,
        ]);

        return $decisao;
    }

    /**
     * R6-1: `success` nunca reenvia. O documento passa a processado pela
     * regra de RF-12 (o `success` prevalece, Q-04), o que conta como
     * progresso do processamento aberto, com aviso aos assinantes dele.
     * Se o webhook do mesmo job chegou antes, o resultado é Repetido, e a
     * decisão é a mesma.
     */
    private function processado(ProcessoDocumento $documento): DecisaoReenvioOcr
    {
        $this->processamentos->aplicarRetorno($documento, true);

        return DecisaoReenvioOcr::Processado;
    }

    /**
     * R6-3: com idade ≥ M, um job ainda em `pending`/`running` é tido como
     * travado. Sem a data do envio, a idade é desconhecida, e o documento
     * não fica preso esperando um job que pode nunca terminar.
     */
    private function atingiuIdadeMaxima(ProcessoDocumento $documento): bool
    {
        $enviadoEm = $documento->ocr_enviado_em;

        if ($enviadoEm === null) {
            return true;
        }

        $idadeMaxima = (int) config('services.n8n_ocr.idade_maxima_absoluta_minutos');

        return $enviadoEm->lte(now()->subMinutes($idadeMaxima));
    }
}
