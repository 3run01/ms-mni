<?php

namespace App\Services\Processo;

use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\TipoDocumento;
use App\Services\Ocr\FormatosOcr;
use Illuminate\Support\Collection;

/**
 * Campos dos autos da lista de `GET /processo/visualizar` (DEP-07 (a);
 * RF-01 a RF-03, CT-01, RNF-02).
 *
 * Prepara, só para essa resposta, os documentos já carregados do processo:
 * - `tipo`: a descrição do tipo na linha do processo, resolvida numa consulta
 *   só para todos os documentos (RF-02, RNF-02);
 * - `ocr_situacao`: a situação calculada pela mesma regra da contagem
 *   (FormatosOcr::situacaoNaLista; RF-03, RNF-03), visível só nesta coleção;
 * - `file_size`: inteiro JSON, se o driver o devolver como string.
 *
 * Nunca grava: a coluna `ocr_situacao` continua como está, e o `$hidden` do
 * model não muda, para que `/documento/visualizar` e
 * `/processo/documentos/listar` sigam sem campo `ocr_*` (RF-04).
 */
class CamposDosAutosService
{
    public function preparar(?Processo $processo): void
    {
        if ($processo === null) {
            return;
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, ProcessoDocumento> $documentos */
        $documentos = $processo->documentos;

        if ($documentos->isEmpty()) {
            return;
        }

        $tipos = $this->tiposDaLinha((int) $processo->tribunal_id, $documentos);

        foreach ($documentos as $documento) {
            $documento->setAttribute('tipo', $tipos->get((string) $documento->tipo_documento));

            // A situação mostrada é calculada, e não a coluna: um mimetype
            // conhecido fora da lista sai `ignorado`, e `null` sai `pendente`.
            // Só o atributo deste model em memória muda; nada chama save().
            $documento->setAttribute(
                'ocr_situacao',
                FormatosOcr::situacaoNaLista($documento->mimetype, $documento->ocr_situacao)
            );

            $fileSize = $documento->file_size;

            if (is_string($fileSize) && is_numeric($fileSize)) {
                $documento->setAttribute('file_size', (int) $fileSize);
            }
        }

        // Visível só nesta coleção: o model continua escondendo `ocr_situacao`
        // nas outras rotas (RF-04).
        $documentos->makeVisible('ocr_situacao');
    }

    /**
     * Descrição por código, numa consulta (DP-03). O SoftDeletes do model tira
     * os excluídos. Com a ordem decrescente de `id`, o `pluck` guarda por
     * último, e portanto fica com, o registro de menor `id` quando o mesmo
     * código se repete na linha (a tabela não tem índice único).
     *
     * @param  Collection<int, ProcessoDocumento>  $documentos
     * @return Collection<string, string>
     */
    private function tiposDaLinha(int $tribunalId, Collection $documentos): Collection
    {
        $codigos = $documentos
            ->pluck('tipo_documento')
            ->filter(fn ($codigo) => $codigo !== null && $codigo !== '')
            ->map(fn ($codigo) => (string) $codigo)
            ->unique()
            ->values()
            ->all();

        if ($codigos === []) {
            return collect();
        }

        return TipoDocumento::query()
            ->where('tribunal_id', $tribunalId)
            ->whereIn('codigo', $codigos)
            ->orderByDesc('id')
            ->pluck('descricao', 'codigo');
    }
}
