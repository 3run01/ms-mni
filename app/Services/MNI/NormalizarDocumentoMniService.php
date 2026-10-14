<?php

namespace App\Services\MNI;

/**
 * Leitura dos `documento` devolvidos pelo MNI, compartilhada pela consulta
 * unitária (consultarProcesso) e pela consulta em lote (consultarProcessos).
 *
 * Helper puro: não faz SOAP, não persiste e não altera o objeto recebido.
 * As regras repetem as de ConsultarDocumentoService para que os dois fluxos
 * classifiquem e codifiquem o conteúdo exatamente do mesmo jeito.
 */
class NormalizarDocumentoMniService
{
    public const MENSAGEM_BLOQUEIO = 'Visualização do documento bloqueada enquanto não houver ciência de seus destinatários';

    public const CLASSIFICACAO_VAZIO = 'vazio';
    public const CLASSIFICACAO_BLOQUEADO = 'bloqueado';
    public const CLASSIFICACAO_OK = 'ok';

    /**
     * Mídias que alguns tribunais já entregam em base64 e outros em binário
     * cru: só são codificadas quando ainda não são base64 válido.
     */
    private const MIMETYPES_BASE64_OPCIONAL = ['video/mp4', 'video/quicktime', 'audio/mpeg', 'audio/mp3'];

    /**
     * Lista plana dos documentos de uma resposta, com os `documentoVinculado`
     * logo depois do documento principal.
     *
     * Aceita o `processo` da consulta unitária, o `processos` da consulta em
     * lote (com `documento` direto ou dentro de `processo`) ou a própria
     * resposta. O SoapClient devolve objeto quando há um elemento e array
     * quando há vários, então as duas formas valem em todos os níveis.
     *
     * Vinculados só em um nível, como na persistência
     * (SalvarDocumentoProcessoService::execute): um nível mais fundo nem é
     * gravado em processo_documentos.
     *
     * @return array<int, object>
     */
    public static function extrairDocumentos(mixed $container): array
    {
        $documentos = [];

        foreach (self::comoLista($container) as $item) {
            if (! is_object($item)) {
                continue;
            }

            foreach (['processos', 'processo'] as $aninhado) {
                if (isset($item->{$aninhado})) {
                    array_push($documentos, ...self::extrairDocumentos($item->{$aninhado}));
                }
            }

            foreach (self::comoLista($item->documento ?? null) as $documento) {
                if (! is_object($documento)) {
                    continue;
                }

                $documentos[] = $documento;

                foreach (self::comoLista($documento->documentoVinculado ?? null) as $vinculado) {
                    if (is_object($vinculado)) {
                        $documentos[] = $vinculado;
                    }
                }
            }
        }

        return $documentos;
    }

    /**
     * Mapa `(string) idDocumento => documento`; a última ocorrência vence,
     * como no laço de ConsultarDocumentoService.
     *
     * O casamento é por string porque `id_documento` é varchar no banco e o
     * model faz cast para integer. O PHP converte chave decimal em int, então
     * procure sempre com `(string) $id`: '123' e 123 caem na mesma chave,
     * '0123' não.
     *
     * @param  array<int, object>  $documentos
     * @return array<string, object>
     */
    public static function indexarPorId(array $documentos): array
    {
        $mapa = [];

        foreach ($documentos as $documento) {
            if (! is_object($documento) || ! isset($documento->idDocumento)) {
                continue;
            }

            $mapa[(string) $documento->idDocumento] = $documento;
        }

        return $mapa;
    }

    /**
     * Classifica o conteúdo cru, antes de normalizarConteudo(): depois da
     * codificação o texto de bloqueio deixa de ser reconhecível.
     */
    public static function classificar(object $documento): string
    {
        $conteudo = $documento->conteudo ?? null;

        if ($conteudo === null || $conteudo === '') {
            return self::CLASSIFICACAO_VAZIO;
        }

        if ($conteudo === self::MENSAGEM_BLOQUEIO) {
            return self::CLASSIFICACAO_BLOQUEADO;
        }

        return self::CLASSIFICACAO_OK;
    }

    /**
     * Cópia do documento com `conteudo` em base64, pela regra que a gravação
     * por mimetype espera. O mimetype é o da resposta do MNI.
     *
     * PDF é sempre codificado, mesmo que já pareça base64 — é o que o fluxo
     * unitário sempre fez e o que os download* decodificam.
     */
    public static function normalizarConteudo(object $documento): object
    {
        $normalizado = clone $documento;
        $conteudo = $normalizado->conteudo ?? null;
        $mimetype = $normalizado->mimetype ?? null;

        if (in_array($mimetype, self::MIMETYPES_BASE64_OPCIONAL, true) && self::ehBase64($conteudo)) {
            return $normalizado;
        }

        $normalizado->conteudo = base64_encode((string) $conteudo);

        return $normalizado;
    }

    private static function ehBase64(mixed $conteudo): bool
    {
        if (! is_string($conteudo)) {
            return false;
        }

        $decodificado = base64_decode($conteudo, true);

        return $decodificado !== false && base64_encode($decodificado) === $conteudo;
    }

    private static function comoLista(mixed $valor): array
    {
        if ($valor === null) {
            return [];
        }

        return is_array($valor) ? $valor : [$valor];
    }
}
