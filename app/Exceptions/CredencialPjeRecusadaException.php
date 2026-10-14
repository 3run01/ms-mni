<?php

namespace App\Exceptions;

/**
 * O tribunal recusou o par login_pje/senha_pje usado na chamada MNI
 * (`loginfailed` no texto cru, antes da padronização).
 *
 * Existe para levar esse sinal por `instanceof` até a camada HTTP, que decide
 * o que responder; a exceção em si não escolhe status HTTP. Estende
 * MNIException para que o texto do tribunal passe pela mesma padronização e
 * continue sendo lido por getError(). Não estende
 * ConsultaMNISemSucessoException, que o download em lote usa para acionar o
 * fallback unitário.
 */
class CredencialPjeRecusadaException extends MNIException
{
}
