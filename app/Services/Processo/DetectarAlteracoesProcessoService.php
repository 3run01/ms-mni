<?php

namespace App\Services\Processo;

use App\Models\Processo;
use Carbon\Carbon;

class DetectarAlteracoesProcessoService
{
    /**
     * Identificadores de movimentos/documentos existentes antes da consulta
     * ao MNI. Processo inexistente → snapshot vazio → tudo é novo
     * (primeira execução).
     */
    public function snapshot(?Processo $processo): array
    {
        if (! $processo) {
            return ['existia' => false, 'movimentos' => [], 'documentos' => []];
        }

        return [
            'existia' => true,
            'movimentos' => $processo->movimentos()->pluck('identificador_movimento')->all(),
            'documentos' => $processo->documentos()->pluck('id_documento')->all(),
        ];
    }

    /**
     * O que apareceu depois do snapshot, já no formato do payload do webhook.
     * Listas limitadas a `pje.monitoramento.limite_itens_payload`; contadores
     * carregam o total real e `truncado` sinaliza o corte.
     */
    public function delta(Processo $processo, array $snapshot): array
    {
        $limite = (int) config('pje.monitoramento.limite_itens_payload');

        $movimentos = $processo->movimentos()
            ->whereNotIn('identificador_movimento', $snapshot['movimentos'])
            ->get(['identificador_movimento', 'codigo_nacional', 'complemento', 'data_hora']);

        $documentos = $processo->documentos()
            ->whereNotIn('id_documento', $snapshot['documentos'])
            ->get(['id_documento', 'descricao', 'tipo_documento', 'mimetype', 'data_hora', 'nivel_sigilo']);

        return [
            'primeira_execucao' => ! $snapshot['existia'],
            'houve_alteracao' => $movimentos->isNotEmpty() || $documentos->isNotEmpty(),
            'movimentos_novos' => $movimentos->count(),
            'documentos_novos' => $documentos->count(),
            'truncado' => $movimentos->count() > $limite || $documentos->count() > $limite,
            'movimentos' => $movimentos->take($limite)->map(fn ($m) => [
                'identificador_movimento' => $m->identificador_movimento,
                'codigo_nacional' => $m->codigo_nacional !== null ? (int) $m->codigo_nacional : null,
                'complemento' => $m->complemento,
                'data_hora' => $this->paraIso8601($m->data_hora),
            ])->values()->all(),
            'documentos' => $documentos->take($limite)->map(fn ($d) => [
                'id_documento' => $d->id_documento,
                'descricao' => $d->descricao,
                'tipo_documento' => $d->tipo_documento,
                'mimetype' => $d->mimetype,
                'data_hora' => $this->paraIso8601($d->data_hora),
                'nivel_sigilo' => $d->nivel_sigilo,
            ])->values()->all(),
        ];
    }

    private function paraIso8601($dataHora): ?string
    {
        return $dataHora ? Carbon::parse($dataHora)->toIso8601String() : null;
    }
}
