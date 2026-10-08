<?php

namespace App\Services\MNI;

/**
 * Reconhece, no texto cru devolvido pelo MNI/PJe, as falhas que a API expõe
 * com código estável: credencial recusada e processo inexistente.
 *
 * Precisa ver o texto antes da padronização do construtor da MNIException:
 * depois dela, getError() já não mostra qual trecho casou.
 *
 * Fica separado de PadronizarMensagemMniService de propósito. A regra de
 * login de lá também casa o prefixo genérico da mensagem do MNI; aqui só
 * `loginfailed` vale como recusa, porque um falso positivo faz o cliente
 * invalidar a credencial do usuário.
 *
 * Helper puro: não faz SOAP, não lança e não decide status HTTP.
 */
class ClassificarFalhaMniService
{
    /**
     * Texto real do TJAP 1º e 2º grau para `sucesso = false` em
     * consultarProcesso com CNJ válido e inexistente. Casa a mensagem
     * inteira: sigilo ou qualquer variação segue como falha não classificada.
     */
    public const PADRAO_PROCESSO_NAO_ENCONTRADO = '/^Processo de número \d+ não encontrado!$/u';

    public static function credencialRecusada(?string $textoCru): bool
    {
        return str_contains(mb_strtolower((string) $textoCru), 'loginfailed');
    }

    public static function processoNaoEncontrado(?string $mensagemCrua): bool
    {
        return preg_match(self::PADRAO_PROCESSO_NAO_ENCONTRADO, (string) $mensagemCrua) === 1;
    }
}
