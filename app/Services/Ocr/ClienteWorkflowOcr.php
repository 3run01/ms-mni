<?php

namespace App\Services\Ocr;

use App\Exceptions\FalhaTransitoriaWorkflowOcrException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Cliente do workflow "Jurídico OCR (Gemini)" do n8n
 * (`.spec/features/ocr-do-processo-para-o-sistema/workflow-ocr.openapi.yaml`):
 * envio (CT-03), consulta do job (CT-05) e cancelamento (CT-07), todos com
 * `Authorization: Bearer {services.n8n_ocr.token}` sobre `services.n8n_ocr.url`.
 *
 * Só traduz a resposta HTTP. O que cada resultado significa para o documento
 * (aguardar, reenviar, processado, com_erro) é decidido por quem chama.
 * Sem nova tentativa aqui: ela é do job que chama, com as regras de RF-11.
 *
 * Nada de log do corpo: o envio leva o link temporário do S3. Os logs levam
 * só a operação, o status HTTP e o `job_id`.
 */
class ClienteWorkflowOcr
{
    /**
     * `POST documents/process`.
     *
     * @param  array<string, mixed>  $corpo  as 5 chaves de EnvioDeDocumentoAoOcr (RF-11)
     *
     * @throws FalhaTransitoriaWorkflowOcrException 5xx, tempo esgotado, falha
     *                                              de rede, 202 sem `id` ou outra resposta
     */
    public function enviarDocumento(array $corpo): ResultadoEnvioOcr
    {
        $operacao = 'POST documents/process';
        $resposta = $this->requisitar($operacao, null, fn (PendingRequest $http) => $http->post(
            $this->url('documents/process'),
            $corpo,
        ));

        if ($resposta->status() === 202) {
            $jobId = $resposta->json('id');

            if (is_string($jobId) && trim($jobId) !== '') {
                return ResultadoEnvioOcr::aceito($jobId);
            }

            // Sem `id` o retorno do job não teria a quem se ligar: tenta de novo.
            throw $this->falha($operacao, 'aceitou sem id do job', 202);
        }

        if ($resposta->status() === 422) {
            $detalhe = $resposta->json('detail');

            return ResultadoEnvioOcr::recusado(
                is_string($detalhe) && trim($detalhe) !== ''
                    ? $detalhe
                    : 'Pedido recusado pelo workflow do OCR (HTTP 422)'
            );
        }

        throw $this->falha($operacao, 'respondeu fora do contrato', $resposta->status());
    }

    /**
     * `GET jobs/{job_id}`.
     *
     * @throws FalhaTransitoriaWorkflowOcrException `status` fora do enum, outra
     *                                              resposta, tempo esgotado ou falha de rede
     */
    public function consultarJob(string $jobId): SituacaoJobOcr
    {
        $operacao = 'GET jobs/{job_id}';
        $resposta = $this->requisitar($operacao, $jobId, fn (PendingRequest $http) => $http->get(
            $this->url('jobs/'.$this->segmentoJob($jobId)),
        ));

        if ($resposta->status() === 404) {
            return SituacaoJobOcr::Inexistente;
        }

        if ($resposta->status() === 200) {
            $situacao = SituacaoJobOcr::doStatusDoWorkflow($resposta->json('status'));

            if ($situacao !== null) {
                return $situacao;
            }

            throw $this->falha($operacao, 'respondeu status fora do contrato', 200, $jobId);
        }

        throw $this->falha($operacao, 'respondeu fora do contrato', $resposta->status(), $jobId);
    }

    /**
     * `DELETE jobs/{job_id}/chunks`. Idempotente no workflow: 200 também
     * sem trechos ou sem job.
     *
     * @throws FalhaTransitoriaWorkflowOcrException qualquer resposta que não
     *                                              seja 200, tempo esgotado ou falha de rede
     */
    public function cancelarJob(string $jobId): void
    {
        $operacao = 'DELETE jobs/{job_id}/chunks';
        $resposta = $this->requisitar($operacao, $jobId, fn (PendingRequest $http) => $http->delete(
            $this->url('jobs/'.$this->segmentoJob($jobId).'/chunks'),
        ));

        if ($resposta->status() === 200) {
            return;
        }

        throw $this->falha($operacao, 'respondeu fora do contrato', $resposta->status(), $jobId);
    }

    /**
     * @param  Closure(PendingRequest): Response  $chamada
     */
    private function requisitar(string $operacao, ?string $jobId, Closure $chamada): Response
    {
        try {
            return $chamada($this->http());
        } catch (ConnectionException $e) {
            throw $this->falha($operacao, 'sem resposta (tempo esgotado ou falha de rede)', null, $jobId, $e);
        }
    }

    private function http(): PendingRequest
    {
        return Http::withToken((string) config('services.n8n_ocr.token'))
            ->acceptJson()
            ->timeout((int) config('services.n8n_ocr.timeout_http_segundos', 30));
    }

    private function url(string $caminho): string
    {
        $base = rtrim((string) config('services.n8n_ocr.url'), '/');

        // Sem a base, a requisição iria para um caminho relativo. Falha
        // transitória para que o envio siga as regras de nova tentativa e a
        // consulta não decida nada.
        if ($base === '') {
            throw new FalhaTransitoriaWorkflowOcrException(
                'Workflow do OCR: base não configurada (services.n8n_ocr.url)'
            );
        }

        return $base.'/'.$caminho;
    }

    /**
     * Um `job_id` vazio viraria `GET jobs/`, cujo 404 seria lido como job
     * inexistente e levaria a reenviar.
     */
    private function segmentoJob(string $jobId): string
    {
        if (trim($jobId) === '') {
            throw new InvalidArgumentException('job_id do workflow do OCR vazio');
        }

        return rawurlencode($jobId);
    }

    private function falha(
        string $operacao,
        string $motivo,
        ?int $statusHttp = null,
        ?string $jobId = null,
        ?Throwable $anterior = null,
    ): FalhaTransitoriaWorkflowOcrException {
        Log::warning("[WorkflowOcr] {$operacao} {$motivo}", array_filter([
            'status' => $statusHttp,
            'job_id' => $jobId,
        ], fn ($valor) => $valor !== null));

        return new FalhaTransitoriaWorkflowOcrException(
            "Workflow do OCR: {$operacao} {$motivo}".($statusHttp !== null ? " (HTTP {$statusHttp})" : ''),
            $statusHttp,
            $anterior,
        );
    }
}
