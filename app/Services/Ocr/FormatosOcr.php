<?php

namespace App\Services\Ocr;

use App\Models\ProcessoDocumento;
use InvalidArgumentException;

/**
 * Formatos que o fluxo de OCR lê (RF-08) e a extensão do `filename` enviado
 * ao workflow (RF-11, NC-1). Puro: não consulta banco, S3 nem configuração.
 *
 * A comparação do mimetype é exata, a mesma do `IN (...)` de
 * ContagemOcrService. Assim, o documento que entra na fila é o mesmo que a
 * contagem chama de legível, e `total + ignorados` fecha com o banco.
 *
 * É também a única fonte do conjunto do total e da situação calculada
 * (RNF-03): a contagem, o pedido, os jobs e a lista de `/processo/visualizar`
 * usam estas constantes e métodos, para que um documento conte em `total`
 * exatamente quando a lista não o mostra `ignorado`.
 */
final class FormatosOcr
{
    /**
     * Mimetype que o tribunal não informou na lista. A coluna
     * `processo_documentos.mimetype` é NOT NULL, então "sem mimetype" é
     * sempre a string vazia.
     */
    public const MIMETYPE_NAO_INFORMADO = '';

    /**
     * Situação só calculada, nunca gravada em `ocr_situacao`: o documento com
     * mimetype conhecido fora da lista legível. Os valores gravados são as
     * constantes `ProcessoDocumento::OCR_*`.
     */
    public const SITUACAO_IGNORADO = 'ignorado';

    /**
     * PDF; HTML, que vai ao OCR pelo PDF que o download já renderiza e grava;
     * e as 6 imagens cuja extensão o nó "Validar pedido" do workflow aceita.
     * Áudio, vídeo e o resto contam em `ignorados`.
     */
    public const MIMETYPES_LEGIVEIS = [
        'application/pdf',
        'text/html',
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/tiff',
        'image/bmp',
        'image/gif',
    ];

    /**
     * O workflow escolhe o MIME pela extensão do `filename` (nó "Arquivo"), e
     * não pelo objeto no S3, que é `.dat` para webp, tiff, bmp e gif. Por
     * isso a extensão sai do mimetype. HTML vai como `.pdf` porque o objeto
     * enviado é o PDF renderizado.
     */
    private const EXTENSOES = [
        'application/pdf' => 'pdf',
        'text/html' => 'pdf',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/tiff' => 'tif',
        'image/bmp' => 'bmp',
        'image/gif' => 'gif',
    ];

    public static function ehLegivel(?string $mimetype): bool
    {
        return $mimetype !== null && in_array($mimetype, self::MIMETYPES_LEGIVEIS, true);
    }

    /**
     * Conjunto do total (RF-07): legível ou mimetype não informado.
     *
     * O `''` entra porque o download do OCR pede o documento ao tribunal e
     * grava o mimetype que vem na resposta (RF-09). Até lá ele não é "fora
     * da lista": é desconhecido, e o OCR ainda pode lê-lo. Por isso conta em
     * `total`, e não em `ignorados`. `ehLegivel('')` continua `false`: legível
     * (pode ir ao envio) e "entra no total" são coisas diferentes.
     */
    public static function entraNoTotal(?string $mimetype): bool
    {
        return $mimetype === self::MIMETYPE_NAO_INFORMADO || self::ehLegivel($mimetype);
    }

    /**
     * Complemento de entraNoTotal: mimetype conhecido fora da lista legível.
     * O `null` cai aqui, como o ELSE do CASE da contagem, para que
     * `total + ignorados` sempre feche (inalcançável: a coluna é NOT NULL).
     */
    public static function ehIgnorado(?string $mimetype): bool
    {
        return ! self::entraNoTotal($mimetype);
    }

    /**
     * Situação do OCR mostrada na lista de `/processo/visualizar` (RF-03).
     * Calculada só para a resposta: a coluna `ocr_situacao` não muda.
     *
     * - mimetype ignorado → `ignorado`, seja qual for a coluna;
     * - senão, `enviado`, `processado` e `erro` saem como estão, e qualquer
     *   outro valor, `null` inclusive, sai `pendente`. É o mesmo critério do
     *   `pendentes` de ContagemOcrService (a negação das 3 situações com
     *   retorno), e é por isso que a lista e a contagem batem (RF-05).
     *
     * Nunca devolve `null`, e um documento com `''` nunca sai `ignorado`.
     */
    public static function situacaoNaLista(?string $mimetype, ?string $situacao): string
    {
        if (self::ehIgnorado($mimetype)) {
            return self::SITUACAO_IGNORADO;
        }

        return match ($situacao) {
            ProcessoDocumento::OCR_ENVIADO,
            ProcessoDocumento::OCR_PROCESSADO,
            ProcessoDocumento::OCR_ERRO => $situacao,
            default => ProcessoDocumento::OCR_PENDENTE,
        };
    }

    /**
     * @throws InvalidArgumentException para mimetype que o OCR não lê: quem
     *                                  envia só recebe documento legível.
     */
    public static function extensaoDoFilename(string $mimetype): string
    {
        if (! array_key_exists($mimetype, self::EXTENSOES)) {
            throw new InvalidArgumentException("Mimetype não legível pelo OCR: {$mimetype}");
        }

        return self::EXTENSOES[$mimetype];
    }
}
