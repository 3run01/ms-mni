<?php

namespace App\Http\Responses;

use App\Exceptions\CredencialPjeRecusadaException;
use App\Exceptions\ProcessoNaoEncontradoNoTribunalException;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Respostas de erro com `codigo` estável (schema ErroComCodigo do contrato)
 * para as falhas do tribunal que o cliente da API trata sem ler texto livre.
 *
 * Fica na camada HTTP porque é ela que escolhe o status: os services só
 * lançam as exceções classificadas. O corpo usa literais fixos e nunca o
 * texto do tribunal, que pode trazer URL ou credencial.
 */
final class ErroComCodigo
{
    public const CODIGO_CREDENCIAL_PJE_RECUSADA = 'credencial_pje_recusada';
    public const CODIGO_PROCESSO_NAO_ENCONTRADO = 'processo_nao_encontrado';

    public const MENSAGEM_CREDENCIAL_PJE_RECUSADA = 'Credencial PJe recusada pelo tribunal';
    public const MENSAGEM_PROCESSO_NAO_ENCONTRADO = 'Processo não encontrado no tribunal';

    /**
     * Procura a falha classificada em $e e na cadeia de getPrevious(): os
     * wrappers do download embrulham a exceção do tribunal como previous.
     *
     * A recusa só conta quando a chamada usou o par enviado pelo cliente; a
     * do par padrão ou da credencial do tribunal não é culpa da credencial do
     * usuário e segue como erro comum.
     */
    public static function classificar(
        Throwable $e,
        bool $parDoCliente,
    ): CredencialPjeRecusadaException|ProcessoNaoEncontradoNoTribunalException|null {
        for ($atual = $e; $atual !== null; $atual = $atual->getPrevious()) {
            if ($atual instanceof ProcessoNaoEncontradoNoTribunalException) {
                return $atual;
            }

            if ($parDoCliente && $atual instanceof CredencialPjeRecusadaException) {
                return $atual;
            }
        }

        return null;
    }

    public static function resposta(
        CredencialPjeRecusadaException|ProcessoNaoEncontradoNoTribunalException $classificada,
    ): JsonResponse {
        if ($classificada instanceof CredencialPjeRecusadaException) {
            return response()->json([
                'codigo' => self::CODIGO_CREDENCIAL_PJE_RECUSADA,
                'message' => self::MENSAGEM_CREDENCIAL_PJE_RECUSADA,
            ], 403);
        }

        return response()->json([
            'codigo' => self::CODIGO_PROCESSO_NAO_ENCONTRADO,
            'message' => self::MENSAGEM_PROCESSO_NAO_ENCONTRADO,
        ], 404);
    }
}
