<?php

namespace App\Jobs;

use App\Exceptions\FalhaTransitoriaWorkflowOcrException;
use App\Models\ProcessoDocumento;
use App\Models\ProcessoOcrProcessamento;
use App\Services\Ocr\ClienteWorkflowOcr;
use App\Services\Ocr\FormatosOcr;
use App\Services\Ocr\MontarEnvioOcrService;
use App\Services\Ocr\ProcessamentoOcrService;
use App\Services\Processo\SalvarDocumentoProcessoService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Envia um documento já gravado no S3 ao workflow "Jurídico OCR (Gemini)"
 * (RF-11, CT-03) e, quando ele já teve um job, cancela antes esse job
 * antigo (CT-07, R2-2).
 *
 * Sem o par do PJe: o job leva só os ids e não é cifrado (RNF-01). O
 * documento já está no S3, e o envio faz 0 chamadas SOAP (RF-09, R2-1).
 *
 * Respostas do workflow (RF-11):
 * - 202 `{id}`: o documento passa a aguardar retorno (`enviado`);
 * - 422: `com_erro` na hora, sem nova tentativa;
 * - 5xx, tempo esgotado ou falha de rede, no `DELETE` ou no `POST`: relança
 *   para a fila tentar de novo; esgotadas as tentativas, `failed()` marca
 *   `com_erro`. O documento não é reenviado sem o 200 do `DELETE`.
 *
 * Mimetype fora da lista legível, `''` inclusive, nunca chega ao workflow
 * (RF-13): o job sai sem requisição e sem falhar, e
 * `MontarEnvioOcrService::corpo` segue como a última barreira.
 */
class EnviarDocumentoAoOcrJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const FILA = 'ocr-envio';

    public int $tries = 5;

    public int $timeout = 60;

    /**
     * Cobre as 5 tentativas com o backoff (60 + 120 + 300 + 600 s) e o
     * tempo de cada uma: a trava por documento não vence no meio das novas
     * tentativas, e o mesmo documento não sai em dois envios paralelos.
     */
    public int $uniqueFor = 1800;

    public function __construct(
        public int $processamentoId,
        public int $documentoId,
    ) {
        $this->onQueue(self::FILA);
    }

    public function uniqueId(): string
    {
        return (string) $this->documentoId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 120, 300, 600];
    }

    public function handle(
        ProcessamentoOcrService $processamentos,
        ClienteWorkflowOcr $workflow,
        MontarEnvioOcrService $montarEnvio,
        SalvarDocumentoProcessoService $salvarDocumento,
    ): void {
        $prefixo = "[ProcessamentoOcr:{$this->processamentoId}]";

        // 1. Processamento encerrado (recusa do par, prazo ou concluído): sai
        //    sem chamar o workflow (D7). O documento fica como está e segue
        //    a regra da abertura seguinte.
        $processamento = ProcessoOcrProcessamento::find($this->processamentoId);

        if ($processamento?->situacao !== ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO) {
            Log::info("{$prefixo} envio do documento {$this->documentoId} ao OCR ignorado: processamento encerrado");

            return;
        }

        $documento = ProcessoDocumento::find($this->documentoId);

        if ($documento === null || (int) $documento->processo_id !== (int) $processamento->processo_id) {
            Log::warning("{$prefixo} documento {$this->documentoId} não pertence ao processo do processamento; envio ignorado");

            return;
        }

        // 2. Processado nunca volta ao workflow; `enviado` já aguarda o
        //    retorno do job atual (RF-18 item 1).
        if (in_array($documento->ocr_situacao, [ProcessoDocumento::OCR_PROCESSADO, ProcessoDocumento::OCR_ENVIADO], true)) {
            return;
        }

        // 3. Só mimetype da lista legível vai ao workflow (RF-13). Sem
        //    `DELETE`, sem `POST`, sem erro definitivo e sem aviso
        //    intermediário: não é falha do envio.
        if (! FormatosOcr::ehLegivel($documento->mimetype)) {
            $this->sairSemMimetypeLegivel($documento, $processamentos);

            return;
        }

        // 4. Sem o objeto no S3 não há o que mandar (D3). O binário também
        //    vira `erro`, para que a reabertura baixe de novo do MNI.
        if (! $this->temObjetoNoS3($documento)) {
            $processamentos->registrarErroDefinitivo($documento, 'Objeto do documento ausente no S3 no envio ao OCR');
            $salvarDocumento->marcarComoErro($documento, 'Objeto ausente no S3 no envio ao OCR', incrementarTentativa: false);

            return;
        }

        // 5. Reenvio de documento que já teve job (R2-2): cancela o job
        //    antigo antes do novo POST. A falha relança, e o documento não
        //    é reenviado sem o 200 do DELETE (RF-18 item 3).
        if (filled($documento->ocr_job_id_cancelar)) {
            $this->cancelarJobAntigo($documento, $workflow);
        }

        // 6. Envio. A exceção transitória relança para a nova tentativa.
        $resultado = $workflow->enviarDocumento($montarEnvio->corpo($documento));

        if ($resultado->aceito) {
            if (! $processamentos->registrarEnvio($documento, $resultado->jobId)) {
                Log::warning("[ProcessoDocumento:{$documento->id}] envio aceito pelo workflow, mas o documento mudou antes do registro", [
                    'job_id' => $resultado->jobId,
                    'situacao' => $documento->ocr_situacao,
                ]);
            }

            return;
        }

        // 422: o workflow recusou o pedido; tentar de novo daria o mesmo.
        $processamentos->registrarErroDefinitivo(
            $documento,
            'Workflow do OCR recusou o documento (HTTP 422): '.$resultado->detalhe,
        );
    }

    /**
     * Tentativas esgotadas (5xx, tempo esgotado ou falha de rede no `DELETE`
     * ou no `POST`): o documento vira `com_erro`, sem ser reenviado no mesmo
     * processamento. Nada muda se ele já foi aceito pelo workflow
     * (`enviado`) ou processado.
     */
    public function failed(?Throwable $e): void
    {
        $documento = ProcessoDocumento::find($this->documentoId);

        if ($documento === null
            || in_array($documento->ocr_situacao, [ProcessoDocumento::OCR_PROCESSADO, ProcessoDocumento::OCR_ENVIADO], true)) {
            return;
        }

        // Só a mensagem da falha do workflow, que leva a operação e o status
        // HTTP; de outras exceções, só a classe, que não traz URL nem corpo.
        $motivo = $e instanceof FalhaTransitoriaWorkflowOcrException
            ? $e->getMessage()
            : ($e !== null ? class_basename($e) : 'motivo desconhecido');

        app(ProcessamentoOcrService::class)->registrarErroDefinitivo(
            $documento,
            "Envio ao workflow do OCR esgotou as tentativas: {$motivo}",
        );

        Log::error("[ProcessamentoOcr:{$this->processamentoId}] envio do documento {$this->documentoId} ao OCR esgotou as tentativas", [
            'erro' => $e !== null ? class_basename($e) : null,
            'status' => $e instanceof FalhaTransitoriaWorkflowOcrException ? $e->statusHttp : null,
        ]);
    }

    /**
     * Documento com mimetype fora da lista legível no envio (RF-13).
     *
     * - Conhecido e fora da lista (por exemplo, regravado pela lista depois
     *   do despacho): saída Q-05 (DP-07). O documento já conta em
     *   `ignorados`, e o processamento só termina `concluido`, com o aviso
     *   final, quando nada mais resta.
     * - `''` (DP-08): inalcançável, porque o pedido e o download nunca mandam
     *   o vazio ao envio. O documento fica pendente, sem mudar a situação, e
     *   o prazo P encerra o processamento se ninguém o revelar.
     */
    private function sairSemMimetypeLegivel(ProcessoDocumento $documento, ProcessamentoOcrService $processamentos): void
    {
        if ($documento->mimetype === FormatosOcr::MIMETYPE_NAO_INFORMADO) {
            Log::warning("[ProcessoDocumento:{$documento->id}] envio ao OCR com mimetype não informado; documento não enviado e mantido pendente", [
                'processamento_id' => $this->processamentoId,
            ]);

            return;
        }

        $concluido = $processamentos->concluirSeNadaResta($this->processamentoId);

        Log::info("[ProcessoDocumento:{$documento->id}] mimetype fora da lista no envio ao OCR; documento não enviado", [
            'mimetype' => $documento->mimetype,
            'processamento_id' => $this->processamentoId,
            'processamento_concluido' => $concluido,
        ]);
    }

    private function temObjetoNoS3(ProcessoDocumento $documento): bool
    {
        return $documento->status === ProcessoDocumento::STATUS_BAIXADO
            && filled($documento->path)
            && Storage::disk('s3')->exists($documento->path);
    }

    /**
     * `DELETE jobs/{job_id}/chunks` do job antigo (CT-07). É idempotente no
     * workflow; depois do 200, a coluna é limpa para que uma nova tentativa
     * do POST não cancele de novo. A limpeza só vale para o mesmo job lido.
     */
    private function cancelarJobAntigo(ProcessoDocumento $documento, ClienteWorkflowOcr $workflow): void
    {
        $jobAntigo = (string) $documento->ocr_job_id_cancelar;

        $workflow->cancelarJob($jobAntigo);

        ProcessoDocumento::query()
            ->whereKey($documento->id)
            ->where('ocr_job_id_cancelar', $jobAntigo)
            ->update(['ocr_job_id_cancelar' => null]);

        $documento->ocr_job_id_cancelar = null;
        $documento->syncOriginalAttribute('ocr_job_id_cancelar');

        Log::info("[ProcessoDocumento:{$documento->id}] job antigo cancelado no workflow do OCR", ['job_id' => $jobAntigo]);
    }
}
