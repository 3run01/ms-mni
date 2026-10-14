<?php

namespace App\Services\Processo;

use App\Exceptions\ConsultaMNISemSucessoException;
use App\Exceptions\MNIException;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Services\MNI\Intercomunicacao\ConsultarDocumentoService;
use App\Services\MNI\Intercomunicacao\ConsultarProcessosService;
use App\Services\MNI\NormalizarDocumentoMniService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Download em lote dos binários não-vídeo de um processo: até T documentos
 * por execução, com uma chamada `consultarProcessos` por página e nenhuma
 * chamada por documento no caminho feliz.
 *
 * Fora do caminho feliz (operação ausente no WSDL ou página com
 * `sucesso = false`), recorre a `consultarProcesso` unitário para os
 * documentos ainda não marcados. Qualquer outra exceção MNI é falha total:
 * os ainda não marcados ficam `erro` e a exceção sobe para a fila.
 *
 * Orquestra e persiste; o SOAP fica nas classes de operação, resolvidas pelo
 * container para que os testes troquem o MNI por mock. O `error` de cada
 * documento sai só por SalvarDocumentoProcessoService::marcarComoErro, que
 * garante exatamente 1 log por marcação.
 */
class BaixarDocumentosEmLoteService
{
    public const MOTIVO_NAO_RETORNADO = 'Documento não retornado pelo MNI na consulta em lote';
    public const MOTIVO_NAO_RETORNADO_UNITARIO = 'Documento não retornado pelo MNI na consulta unitária';
    public const MOTIVO_VAZIO = 'Conteúdo do documento vazio na resposta do MNI';

    private SalvarDocumentoProcessoService $salvarDocumento;

    /**
     * Elegíveis ainda não marcados `baixado` nem `erro` nesta execução, por
     * `(string) id_documento`. Sair daqui é o que impede marcar ou contar o
     * mesmo documento duas vezes numa execução.
     *
     * @var array<string, ProcessoDocumento>
     */
    private array $naoMarcados = [];

    private int $baixados = 0;

    private int $erros = 0;

    private int $consultasLote = 0;

    private int $consultasUnitarias = 0;

    /**
     * @param  array<int, int>  $processoDocumentoIds  ids de `processo_documentos`, não `id_documento`
     *
     * @throws MNIException na falha total (processo sem tribunal ativo ou exceção numa
     *                      chamada SOAP), depois de marcar `erro` nos ainda não marcados
     */
    public function executar(int $processoId, array $processoDocumentoIds, ?string $login = null, ?string $senha = null): void
    {
        $this->salvarDocumento = app(SalvarDocumentoProcessoService::class);
        $this->naoMarcados = [];
        $this->baixados = 0;
        $this->erros = 0;
        $this->consultasLote = 0;
        $this->consultasUnitarias = 0;

        $processo = Processo::with('tribunal')->find($processoId);

        if (!$processo) {
            // Processo apagado depois do despacho: não há o que baixar nem onde marcar.
            Log::warning("[Processo:{$processoId}] lote ignorado: processo não encontrado", [
                'processo_documento_ids' => $processoDocumentoIds,
            ]);

            return;
        }

        $this->naoMarcados = $this->carregarElegiveis($processo, $processoDocumentoIds);

        if ($this->naoMarcados === []) {
            Log::info("[Processo:{$processo->numero_processo}] lote sem documentos elegíveis; MNI não consultado", [
                'processo_documento_ids' => $processoDocumentoIds,
            ]);

            return;
        }

        try {
            // Checado depois da elegibilidade: sem elegíveis não há o que marcar
            // e o lote termina sem exceção, mesmo sem tribunal.
            if (!$processo->tribunal) {
                throw new MNIException('Processo não possui tribunal associado', 500);
            }

            $this->obterConteudos($processo, $login, $senha);
        } catch (MNIException $e) {
            $this->encerrarComFalhaTotal($processo, $e);

            throw $e;
        }

        foreach ($this->naoMarcados as $documento) {
            $this->marcarErro($documento, self::MOTIVO_NAO_RETORNADO);
        }

        Log::info("[Processo:{$processo->numero_processo}] lote concluído", $this->resumo());
    }

    /**
     * Documentos do lote que ainda precisam do MNI, na ordem do lote.
     *
     * `baixado` com o objeto no S3 fica de fora sem nenhuma escrita: reenviar
     * ao MNI um documento já gravado é o que o lote existe para evitar.
     * `baixado` sem o objeto volta a ser baixado, como no fluxo unitário.
     *
     * @param  array<int, int>  $processoDocumentoIds
     * @return array<string, ProcessoDocumento>
     */
    private function carregarElegiveis(Processo $processo, array $processoDocumentoIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $processoDocumentoIds)));

        // Filtrar pelo processo impede que um id de outro processo entre na consulta deste.
        $documentos = ProcessoDocumento::where('processo_id', $processo->id)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $elegiveis = [];

        foreach ($ids as $id) {
            $documento = $documentos->get($id);

            if (!$documento || $this->baixadoNoS3($documento)) {
                continue;
            }

            // Evita recarregar o processo (e os eager loads dele) a cada gravação.
            $documento->setRelation('processo', $processo);

            // Em paridade com baixarDocumento(): a tentativa nova começa como pendente.
            if ($documento->status === ProcessoDocumento::STATUS_ERRO) {
                $documento->status = ProcessoDocumento::STATUS_PENDENTE;
                $documento->save();
            }

            $elegiveis[(string) $documento->id_documento] = $documento;
        }

        return $elegiveis;
    }

    private function baixadoNoS3(ProcessoDocumento $documento): bool
    {
        return $documento->status === ProcessoDocumento::STATUS_BAIXADO
            && !empty($documento->path)
            && Storage::disk('s3')->exists($documento->path);
    }

    /**
     * Caminho feliz por `consultarProcessos`; fallback unitário quando o
     * tribunal não expõe a operação ou uma página volta com `sucesso = false`.
     *
     * Exceções que não sejam `sucesso = false` sobem sem tratamento: são a
     * falha total, decidida por quem chama.
     *
     * @throws MNIException
     */
    private function obterConteudos(Processo $processo, ?string $login, ?string $senha): void
    {
        $consultarProcessos = app(ConsultarProcessosService::class);

        if (!$consultarProcessos->operacaoDisponivel($processo->tribunal)) {
            // Em warning para medir quantos tribunais ficam fora do lote.
            Log::warning("[Processo:{$processo->numero_processo}] lote: tribunal sem consultarProcessos no WSDL; usando consulta unitária", [
                'tribunal_id' => $processo->tribunal->id,
                'documentos' => count($this->naoMarcados),
            ]);

            $this->consultarUmAUm($processo, $login, $senha);

            return;
        }

        try {
            $this->paginar($consultarProcessos, $processo, $login, $senha);
        } catch (ConsultaMNISemSucessoException $e) {
            // O tribunal respondeu, só não pelo lote: os já marcados nas
            // páginas anteriores ficam como estão e o resto vai um a um.
            Log::warning("[Processo:{$processo->numero_processo}] lote: consultarProcessos sem sucesso; usando consulta unitária", [
                'pagina' => $this->consultasLote,
                'mensagem' => $e->getError(),
                'documentos' => count($this->naoMarcados),
            ]);

            $this->consultarUmAUm($processo, $login, $senha);
        }
    }

    /**
     * Lê `consultarProcessos` da página 1 até P e para assim que todos os
     * elegíveis voltaram ou uma página vem vazia. `totalRegistros` não é
     * usado: a amostra sugere que ele conta processos, não documentos.
     *
     * @throws ConsultaMNISemSucessoException quando uma página volta com `sucesso = false`
     * @throws MNIException nas demais falhas da chamada
     */
    private function paginar(ConsultarProcessosService $consultarProcessos, Processo $processo, ?string $login, ?string $senha): void
    {
        $parametros = app(ParametrosDownloadEmLote::class);
        $maxPaginas = $parametros->maxPaginas();
        $tamanhoPagina = $parametros->tamanhoPagina();

        // A mesma lista em todas as páginas, mesmo depois de parte dela voltar.
        $idsDocumentos = array_map(
            fn (ProcessoDocumento $documento) => (string) $documento->id_documento,
            array_values($this->naoMarcados)
        );

        for ($pagina = 1; $pagina <= $maxPaginas && $this->naoMarcados !== []; $pagina++) {
            $this->consultasLote++;
            $documentosPagina = $consultarProcessos->consultarPagina(
                $processo->tribunal,
                (string) $processo->numero_processo,
                $idsDocumentos,
                $pagina,
                $tamanhoPagina,
                $login,
                $senha,
            );

            if ($documentosPagina === []) {
                break;
            }

            // Grava e solta cada documento antes do próximo: uma página com
            // áudios pode ocupar centenas de MB entre DOM e base64.
            foreach (array_keys($documentosPagina) as $idDocumento) {
                $documentoMni = $documentosPagina[$idDocumento];
                unset($documentosPagina[$idDocumento]);

                // Já visto numa página anterior ou não pedido neste lote: ignorado.
                $documento = $this->naoMarcados[(string) $idDocumento] ?? null;

                if ($documento) {
                    $this->tratarDocumento($documento, $documentoMni);
                }

                unset($documentoMni);
            }

            unset($documentosPagina);
        }
    }

    /**
     * Fallback unitário (`consultarProcesso`, um documento por chamada) para
     * os ainda não marcados, na ordem do lote.
     *
     * `sucesso = false` numa chamada é falha só daquele documento; qualquer
     * outra exceção sobe e encerra as consultas do lote, inclusive as dos
     * documentos ainda não pedidos.
     *
     * @throws MNIException
     */
    private function consultarUmAUm(Processo $processo, ?string $login, ?string $senha): void
    {
        $consultarDocumento = app(ConsultarDocumentoService::class);

        // O foreach percorre uma cópia: marcar tira o documento de naoMarcados.
        foreach ($this->naoMarcados as $documento) {
            $this->consultasUnitarias++;

            try {
                $documentoMni = $consultarDocumento->buscar(
                    $processo->tribunal,
                    (string) $processo->numero_processo,
                    (string) $documento->id_documento,
                    $login,
                    $senha,
                );
            } catch (ConsultaMNISemSucessoException $e) {
                $this->marcarErro($documento, $e->getError());

                continue;
            }

            if ($documentoMni === null) {
                $this->marcarErro($documento, self::MOTIVO_NAO_RETORNADO_UNITARIO);

                continue;
            }

            $this->tratarDocumento($documento, $documentoMni);
            unset($documentoMni);
        }
    }

    /**
     * Falha total: `erro` só nos ainda não marcados nesta execução. Quem já
     * ficou `baixado` ou `erro` mantém o que recebeu, sem outro +1 nem outro
     * log. Sem `Log::error` do lote: o RNF-03 conta 1 por documento.
     */
    private function encerrarComFalhaTotal(Processo $processo, MNIException $e): void
    {
        $motivo = $e->getError();
        $atingidos = count($this->naoMarcados);

        foreach ($this->naoMarcados as $documento) {
            $this->marcarErro($documento, $motivo);
        }

        Log::warning("[Processo:{$processo->numero_processo}] lote encerrado por falha total do MNI", [
            'motivo' => $motivo,
            'marcados_por_falha_total' => $atingidos,
            ...$this->resumo(),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function resumo(): array
    {
        return [
            'consultas_lote' => $this->consultasLote,
            'consultas_unitarias' => $this->consultasUnitarias,
            'baixados' => $this->baixados,
            'erros' => $this->erros,
        ];
    }

    /**
     * Um documento devolvido pelo MNI vira `baixado` ou `erro`; nunca lança,
     * para que a falha de um não derrube os demais do lote.
     */
    private function tratarDocumento(ProcessoDocumento $documento, object $documentoMni): void
    {
        $classificacao = NormalizarDocumentoMniService::classificar($documentoMni);

        if ($classificacao === NormalizarDocumentoMniService::CLASSIFICACAO_VAZIO) {
            $this->marcarErro($documento, self::MOTIVO_VAZIO);

            return;
        }

        if ($classificacao === NormalizarDocumentoMniService::CLASSIFICACAO_BLOQUEADO) {
            $this->marcarErro($documento, NormalizarDocumentoMniService::MENSAGEM_BLOQUEIO);

            return;
        }

        try {
            $path = $this->salvarDocumento->salvarConteudo(
                $documento,
                NormalizarDocumentoMniService::normalizarConteudo($documentoMni)
            );

            if (!Storage::disk('s3')->exists($path)) {
                throw new \RuntimeException("Objeto {$path} ausente no S3 após a gravação");
            }
        } catch (\Throwable $e) {
            $this->marcarErro($documento, 'Falha ao gravar o documento: ' . $e->getMessage());

            return;
        }

        unset($this->naoMarcados[(string) $documento->id_documento]);
        $documento->tentativas_download = (int) $documento->tentativas_download + 1;
        $this->salvarDocumento->marcarComoBaixado($documento, $path);
        $this->baixados++;
    }

    private function marcarErro(ProcessoDocumento $documento, string $motivo): void
    {
        unset($this->naoMarcados[(string) $documento->id_documento]);
        $this->salvarDocumento->marcarComoErro($documento, $motivo);
        $this->erros++;
    }
}
