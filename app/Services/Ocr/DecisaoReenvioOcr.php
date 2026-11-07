<?php

namespace App\Services\Ocr;

/**
 * O que fazer com um documento aguardando retorno com idade ≥ A, depois da
 * consulta do job antigo no workflow (D9; RF-18 item 3, R5-1, R6-1 a R6-3,
 * R7-1). Decidido por VerificarJobAntigoOcrService::verificar.
 *
 * - Reenviar: 404, `failed`, `cancelled`, ou `pending`/`running` com idade
 *   ≥ M. Quem chama devolve o documento à fila (o job antigo deixa de ser
 *   conhecido, RF-12) e despacha o envio, que cancela o job antigo
 *   (CT-07) antes do novo `POST`.
 * - Aguardar: `pending`/`running` com idade < M, ou a consulta falhou
 *   (tempo esgotado, 5xx, falha de rede, `status` fora do contrato). O
 *   documento segue aguardando retorno, com o job conhecido, e é
 *   consultado de novo na rodada seguinte do acompanhamento.
 * - Processado: `success`. O documento já passou a processado pela regra de
 *   RF-12 (Q-04), o que conta como progresso, e não é reenviado.
 */
enum DecisaoReenvioOcr: string
{
    case Reenviar = 'reenviar';
    case Aguardar = 'aguardar';
    case Processado = 'processado';
}
