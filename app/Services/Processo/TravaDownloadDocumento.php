<?php

namespace App\Services\Processo;

use Illuminate\Support\Facades\Cache;

/**
 * Trava por documento do download em lote: um documento que já está num lote
 * enfileirado e ainda não concluído não entra em outro lote.
 *
 * Substitui, para o lote, o ShouldBeUnique por documento do
 * BaixarDocumentoMNIJob, com a mesma validade e no mesmo cache store padrão
 * — por isso exige um store compartilhado entre web, CLI e worker.
 *
 * O dono identifica o despacho que adquiriu a trava. Liberar exige o mesmo
 * dono, para que um lote atrasado não derrube a trava que outro lote
 * readquiriu depois da expiração.
 */
class TravaDownloadDocumento
{
    /**
     * Em paridade com BaixarDocumentoMNIJob::$uniqueFor.
     */
    public const TTL_SEGUNDOS = 1800;

    /**
     * Aquisição atômica: a própria tentativa é a verificação de trava ativa,
     * sem janela entre checar e travar.
     */
    public function adquirir(int $processoDocumentoId, string $dono): bool
    {
        return Cache::lock($this->chave($processoDocumentoId), self::TTL_SEGUNDOS, $dono)->get();
    }

    public function liberar(int $processoDocumentoId, string $dono): void
    {
        Cache::restoreLock($this->chave($processoDocumentoId), $dono)->release();
    }

    /**
     * @param  array<int, int>  $processoDocumentoIds
     */
    public function liberarTodas(array $processoDocumentoIds, string $dono): void
    {
        foreach ($processoDocumentoIds as $processoDocumentoId) {
            $this->liberar((int) $processoDocumentoId, $dono);
        }
    }

    private function chave(int $processoDocumentoId): string
    {
        return "mni-download:documento:{$processoDocumentoId}";
    }
}
