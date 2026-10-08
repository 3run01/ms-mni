<?php

namespace App\Exceptions;

/**
 * O MNI respondeu `sucesso = false` dizendo que o processo não existe no
 * tribunal (`Processo de número <dígitos> não encontrado!`).
 *
 * Existe para levar esse sinal por `instanceof` até a camada HTTP, que decide
 * o que responder; a exceção em si não escolhe status HTTP. Estende
 * MNIException para que o texto do tribunal passe pela mesma padronização e
 * continue sendo lido por getError(). Não estende
 * ConsultaMNISemSucessoException, que o download em lote usa para acionar o
 * fallback unitário.
 */
class ProcessoNaoEncontradoNoTribunalException extends MNIException
{
}
