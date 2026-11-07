<?php

namespace App\Services\Ocr;

/**
 * Resposta do workflow a `POST documents/process` (CT-03) que decide algo:
 * aceito (202 `{id}`), e o documento fica aguardando retorno; ou recusado
 * (422 `{detail}`), e o documento vira `com_erro` sem nova tentativa (RF-11).
 * O resto não chega aqui: vira FalhaTransitoriaWorkflowOcrException.
 */
final readonly class ResultadoEnvioOcr
{
    private function __construct(
        public bool $aceito,
        public ?string $jobId,
        public ?string $detalhe,
    ) {}

    public static function aceito(string $jobId): self
    {
        return new self(true, $jobId, null);
    }

    public static function recusado(string $detalhe): self
    {
        return new self(false, null, $detalhe);
    }
}
