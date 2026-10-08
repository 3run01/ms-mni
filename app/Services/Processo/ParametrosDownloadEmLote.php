<?php

namespace App\Services\Processo;

/**
 * Parâmetros T (tamanho do lote) e P (teto de páginas) do download de
 * documentos em lote.
 *
 * Sem estado de propósito: lê config() a cada chamada, para que um override
 * em teste ou um `config:cache` novo valha sem reinstanciar nada. Valor
 * inválido nunca derruba o job — volta ao padrão.
 */
class ParametrosDownloadEmLote
{
    public const TAMANHO_LOTE_PADRAO = 20;

    public function tamanhoLote(): int
    {
        return $this->inteiroPositivo(config('pje.download_documentos.tamanho_lote'))
            ?? self::TAMANHO_LOTE_PADRAO;
    }

    /**
     * Igual a T (premissa Q-02 do SPEC): sem evidência de como cada tribunal
     * conta a página, pedir T por página é o que cabe o lote inteiro na
     * primeira chamada.
     */
    public function tamanhoPagina(): int
    {
        return $this->tamanhoLote();
    }

    /**
     * Padrão ceil(T / tamanhoPagina) + 1: as páginas que comportam o lote e
     * mais uma, para o tribunal que devolve menos documentos por página do
     * que o pedido.
     */
    public function maxPaginas(): int
    {
        return $this->inteiroPositivo(config('pje.download_documentos.max_paginas'))
            ?? (int) ceil($this->tamanhoLote() / $this->tamanhoPagina()) + 1;
    }

    private function inteiroPositivo(mixed $valor): ?int
    {
        if (! is_numeric($valor) || (int) $valor < 1) {
            return null;
        }

        return (int) $valor;
    }
}
