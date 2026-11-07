<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * O workflow "Jurídico OCR (Gemini)" não deu uma resposta que decida algo:
 * 5xx, tempo esgotado, falha de rede ou resposta fora do contrato
 * (`workflow-ocr.openapi.yaml`). Quem chama decide o que fazer: o envio e o
 * cancelamento tentam de novo (RF-11, RF-18); a consulta do job não reenvia
 * e consulta de novo na rodada seguinte (R6-2).
 *
 * A mensagem leva a operação e o status HTTP, nunca o corpo da requisição
 * ou da resposta: o corpo do envio tem o link temporário do S3.
 */
class FalhaTransitoriaWorkflowOcrException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusHttp = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
