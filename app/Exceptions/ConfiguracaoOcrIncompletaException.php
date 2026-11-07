<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * O bloco `services.n8n_ocr` não tem a base do workflow (`url`) ou o token
 * (`token`). Sem eles, nenhum documento chegaria ao workflow, e o pedido de
 * OCR não abre processamento: sai antes da trava e de qualquer chamada SOAP.
 * Serve de desligamento natural do OCR antes da configuração.
 *
 * A mensagem leva só os nomes das chaves ausentes, nunca valores.
 */
class ConfiguracaoOcrIncompletaException extends RuntimeException
{
    /**
     * @param  list<string>  $chavesAusentes  ex.: ['services.n8n_ocr.url']
     */
    public function __construct(public readonly array $chavesAusentes)
    {
        parent::__construct('Configuração do workflow do OCR incompleta: '.implode(', ', $chavesAusentes));
    }
}
