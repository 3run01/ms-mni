<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * O pedido de OCR esperou W segundos (`services.n8n_ocr.espera_da_trava_segundos`)
 * pela decisão do mesmo processo e linha, presa por outro pedido, e desistiu
 * (RF-06, Q-02). Nada foi feito: 0 chamadas SOAP, 0 assinantes e 0 jobs.
 *
 * A exceção não escolhe status HTTP: a camada HTTP responde 500 `{message}`.
 * A mensagem não leva o par nem o callback do pedido.
 */
class EsperaDaTravaOcrEsgotadaException extends RuntimeException
{
    public function __construct(
        public readonly int $esperaSegundos,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            "A espera de {$esperaSegundos} s pela decisão do pedido de OCR deste processo e linha se esgotou",
            0,
            $previous,
        );
    }
}
