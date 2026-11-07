<?php

namespace App\Services\Ocr;

/**
 * O que uma mudança de situação do documento no OCR fez (RF-12):
 *
 * - `Aplicado`: a situação mudou, e a mudança conta como progresso do
 *   processamento aberto, se houver (RF-13, RF-14, RF-18 item 2);
 * - `Repetido`: o mesmo resultado já estava aplicado (o 2º `success`, o 2º
 *   `error`). 200 no webhook, sem mudança e com 0 avisos;
 * - `SemMudanca`: a regra não deixa mudar. Um `error` nunca rebaixa um
 *   processado (Q-04), e o retorno de um job que já não é o atual do
 *   documento não vale (R2-2). 0 avisos.
 */
enum ResultadoRetornoOcr: string
{
    case Aplicado = 'aplicado';
    case Repetido = 'repetido';
    case SemMudanca = 'sem_mudanca';

    public function mudou(): bool
    {
        return $this === self::Aplicado;
    }
}
