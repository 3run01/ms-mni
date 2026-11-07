<?php

namespace App\Services\Ocr;

/**
 * Situação de um job do workflow, lida por `GET jobs/{job_id}` (CT-05).
 *
 * Enum sem valor de propósito: `Inexistente` é o 404, e não um `status` do
 * workflow. Com um enum de string, um 200 com esse texto no `status` viraria
 * job inexistente. A tradução do `status` fica em `doStatusDoWorkflow`.
 *
 * O que cada situação significa (processado, erro, reenvio ou espera) é
 * decidido por quem consulta (RF-13 item 1, RF-18 item 3), não aqui.
 */
enum SituacaoJobOcr
{
    case Pendente;
    case EmExecucao;
    case Sucesso;
    case Falhou;
    case Cancelado;
    case Inexistente;

    /**
     * @return self|null null para `status` fora do enum do contrato
     */
    public static function doStatusDoWorkflow(mixed $status): ?self
    {
        return match ($status) {
            'pending' => self::Pendente,
            'running' => self::EmExecucao,
            'success' => self::Sucesso,
            'failed' => self::Falhou,
            'cancelled' => self::Cancelado,
            default => null,
        };
    }

    /**
     * `pending` e `running`: o job ainda pode gravar trechos e chamar o webhook.
     */
    public function emAndamento(): bool
    {
        return $this === self::Pendente || $this === self::EmExecucao;
    }
}
