<?php

namespace App\Exceptions;

/**
 * O MNI respondeu, mas com `sucesso = false` (consultarProcessos ou
 * consultarProcesso).
 *
 * Existe para ser separada por `instanceof` das falhas de transporte, SOAP
 * fault e timeout, que chegam como MNIException comum: no download em lote,
 * `sucesso = false` aciona o fallback unitário, enquanto as demais são falha
 * total do job. Estende MNIException para que a mensagem do tribunal passe
 * pela mesma padronização e continue sendo lida por getError().
 */
class ConsultaMNISemSucessoException extends MNIException
{
}
