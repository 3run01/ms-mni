<?php

namespace App\Services\Ocr;

/**
 * Resposta do pedido de OCR (`ProcessamentoDeOcr`, CT-01): as contagens de
 * RF-08 do processo na linha e a situação.
 *
 * - `em_andamento`: o pedido abriu um processamento (RF-04) ou entrou como
 *   carona num aberto (RF-06);
 * - `concluido`: não havia documento pendente, em `com_erro` nem aguardando
 *   retorno, e nada foi aberto (RF-07).
 *
 * `processamentoId` e `carona` são internos (logs e testes) e ficam fora do
 * corpo do 202.
 */
final readonly class ResultadoSolicitacaoOcr
{
    public const SITUACAO_EM_ANDAMENTO = 'em_andamento';

    public const SITUACAO_CONCLUIDO = 'concluido';

    public function __construct(
        public string $numeroProcesso,
        public int $tribunalId,
        public int $total,
        public int $processados,
        public int $comErro,
        public int $ignorados,
        public string $situacao,
        public ?int $processamentoId = null,
        public bool $carona = false,
    ) {}

    public static function deContagem(
        DadosSolicitacaoOcr $dados,
        ContagemOcr $contagem,
        string $situacao,
        ?int $processamentoId = null,
        bool $carona = false,
    ): self {
        return new self(
            numeroProcesso: $dados->numeroProcesso,
            tribunalId: $dados->tribunalId,
            total: $contagem->total,
            processados: $contagem->processados,
            comErro: $contagem->comErro,
            ignorados: $contagem->ignorados,
            situacao: $situacao,
            processamentoId: $processamentoId,
            carona: $carona,
        );
    }

    /**
     * Corpo do 202, com exatamente as chaves de `ProcessamentoDeOcr`.
     *
     * @return array{numero_processo: string, tribunal_id: int, total: int, processados: int, com_erro: int, ignorados: int, situacao: string}
     */
    public function paraCorpo(): array
    {
        return [
            'numero_processo' => $this->numeroProcesso,
            'tribunal_id' => $this->tribunalId,
            'total' => $this->total,
            'processados' => $this->processados,
            'com_erro' => $this->comErro,
            'ignorados' => $this->ignorados,
            'situacao' => $this->situacao,
        ];
    }
}
