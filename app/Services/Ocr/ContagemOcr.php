<?php

namespace App\Services\Ocr;

/**
 * Contagens do OCR de um processo na linha, acumuladas entre processamentos
 * (RF-08). Cada legível está numa só situação, então
 * `processados + comErro + pendentes + aguardando = total`.
 */
final readonly class ContagemOcr
{
    public function __construct(
        public int $total,
        public int $processados,
        public int $comErro,
        public int $ignorados,
        public int $pendentes,
        public int $aguardando,
    ) {}

    /**
     * As 4 contagens de `ProcessamentoDeOcr` e de `AvisoDeProgressoDoOcr`.
     * `pendentes` e `aguardando` são internas: decidem a abertura e o fim do
     * processamento, mas não fazem parte do contrato.
     *
     * @return array{total: int, processados: int, com_erro: int, ignorados: int}
     */
    public function paraCorpo(): array
    {
        return [
            'total' => $this->total,
            'processados' => $this->processados,
            'com_erro' => $this->comErro,
            'ignorados' => $this->ignorados,
        ];
    }
}
