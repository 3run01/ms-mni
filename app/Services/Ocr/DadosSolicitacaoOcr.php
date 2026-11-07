<?php

namespace App\Services\Ocr;

/**
 * O pedido de OCR já validado (`SolicitacaoDeOcr`, RF-03): o processo, a
 * linha, o par do PJe de quem pede e o callback dos avisos.
 *
 * O par e o `callback_token` são sensíveis (RNF-01): `#[\SensitiveParameter]`
 * os tira do trace das exceções, e `__debugInfo()` os mascara em `dump()`,
 * `var_dump()` e `print_r()`. O par só segue para a consulta ao MNI e para o
 * job de download cifrado do dono; nunca vai para tabela.
 */
final readonly class DadosSolicitacaoOcr
{
    private const MASCARA = '***';

    /**
     * @param  string  $numeroProcesso  20 dígitos, já sem máscara (`cleanNumeroProcesso`)
     * @param  ?int  $apiTokenId  o token da API que fez o pedido, se conhecido
     */
    public function __construct(
        public string $numeroProcesso,
        public int $tribunalId,
        #[\SensitiveParameter] public string $login,
        #[\SensitiveParameter] public string $senha,
        public string $callbackUrl,
        #[\SensitiveParameter] public string $callbackToken,
        public ?int $apiTokenId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'numeroProcesso' => $this->numeroProcesso,
            'tribunalId' => $this->tribunalId,
            'login' => self::MASCARA,
            'senha' => self::MASCARA,
            'callbackUrl' => $this->callbackUrl,
            'callbackToken' => self::MASCARA,
            'apiTokenId' => $this->apiTokenId,
        ];
    }
}
