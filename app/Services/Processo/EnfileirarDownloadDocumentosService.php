<?php

namespace App\Services\Processo;

use App\Jobs\BaixarDocumentoMNIJob;
use App\Jobs\BaixarDocumentosMNIEmLoteJob;
use App\Models\ProcessoDocumento;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Ponto único de despacho do download de binários: separa os vídeos, que
 * seguem com 1 BaixarDocumentoMNIJob por documento, e agrupa os demais por
 * processo em lotes de até T, um BaixarDocumentosMNIEmLoteJob por lote.
 *
 * Todo documento não-vídeo passa pela TravaDownloadDocumento antes de entrar
 * num lote: quem já está num lote enfileirado e não concluído fica fora e
 * não conta no tamanho da seleção.
 */
class EnfileirarDownloadDocumentosService
{
    public const FILA = 'mni-download';

    /**
     * Seguem o caminho atual (script bash), fora de qualquer lote.
     */
    public const MIMETYPES_VIDEO = ['video/mp4', 'video/quicktime'];

    public function __construct(
        private ParametrosDownloadEmLote $parametros,
        private TravaDownloadDocumento $trava,
    ) {}

    /**
     * @param  iterable<ProcessoDocumento>  $documentos  seleção da origem do despacho
     * @return array{lotes: int, documentos_em_lote: int, videos: int, ignorados_trava: int}
     *
     * @throws \Throwable quando o despacho de um lote falha, depois de soltar as
     *                    travas que nenhum job enfileirado vai liberar
     */
    public function enfileirar(iterable $documentos, ?string $login = null, ?string $senha = null): array
    {
        $resumo = ['lotes' => 0, 'documentos_em_lote' => 0, 'videos' => 0, 'ignorados_trava' => 0];
        $naoVideosPorProcesso = [];

        foreach ($this->selecionarElegiveis($documentos) as $documento) {
            if (in_array($documento->mimetype, self::MIMETYPES_VIDEO, true)) {
                // Inalterado: o ShouldBeUnique do job já impede vídeo duplicado.
                BaixarDocumentoMNIJob::dispatch($documento, $login, $senha)->onQueue(self::FILA);
                $resumo['videos']++;

                continue;
            }

            $naoVideosPorProcesso[$documento->processo_id][] = $documento;
        }

        // Um dono por chamada: só os jobs desta seleção liberam estas travas.
        $dono = (string) Str::uuid();
        $lotes = [];

        foreach ($naoVideosPorProcesso as $documentosDoProcesso) {
            $travados = collect($documentosDoProcesso)
                ->filter(fn (ProcessoDocumento $documento) => $this->trava->adquirir($documento->id, $dono))
                ->values();

            $resumo['ignorados_trava'] += count($documentosDoProcesso) - $travados->count();

            foreach ($travados->chunk($this->parametros->tamanhoLote()) as $lote) {
                $lotes[] = $lote->values();
            }
        }

        foreach ($lotes as $indice => $lote) {
            try {
                $this->despacharLote($lote, $dono, $login, $senha);
            } catch (\Throwable $e) {
                // Este lote e os seguintes não têm job para soltar a trava;
                // sem isso os documentos ficariam 1800 s fora de qualquer lote.
                $naoDespachados = collect(array_slice($lotes, $indice))->flatten(1)->pluck('id')->all();
                $this->trava->liberarTodas($naoDespachados, $dono);

                throw $e;
            }

            $resumo['lotes']++;
            $resumo['documentos_em_lote'] += $lote->count();
        }

        return $resumo;
    }

    /**
     * Primeira ocorrência de cada id, só `pendente` ou `erro`. Status null
     * conta como `pendente`: depois de updateOrCreate o default da coluna não
     * é hidratado no model.
     *
     * @param  iterable<ProcessoDocumento>  $documentos
     * @return array<int, ProcessoDocumento>
     */
    private function selecionarElegiveis(iterable $documentos): array
    {
        $elegiveis = [];

        foreach ($documentos as $documento) {
            $status = $documento->status ?? ProcessoDocumento::STATUS_PENDENTE;

            if (isset($elegiveis[$documento->id])
                || !in_array($status, [ProcessoDocumento::STATUS_PENDENTE, ProcessoDocumento::STATUS_ERRO], true)) {
                continue;
            }

            $elegiveis[$documento->id] = $documento;
        }

        return $elegiveis;
    }

    /**
     * @param  Collection<int, ProcessoDocumento>  $lote
     */
    private function despacharLote(Collection $lote, string $dono, ?string $login, ?string $senha): void
    {
        BaixarDocumentosMNIEmLoteJob::dispatch(
            $lote->first()->processo_id,
            $lote->pluck('id')->all(),
            $lote->mapWithKeys(fn (ProcessoDocumento $documento) => [
                $documento->id => (int) ($documento->tentativas_download ?? 0),
            ])->all(),
            $dono,
            $login,
            $senha,
        )->onQueue(self::FILA);
    }
}
