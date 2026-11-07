<?php

namespace App\Services\Ocr;

use InvalidArgumentException;

/**
 * Formatos que o fluxo de OCR lê (RF-08) e a extensão do `filename` enviado
 * ao workflow (RF-11, NC-1). Puro: não consulta banco, S3 nem configuração.
 *
 * A comparação do mimetype é exata, a mesma do `IN (...)` de
 * ContagemOcrService. Assim, o documento que entra na fila é o mesmo que a
 * contagem chama de legível, e `total + ignorados` fecha com o banco.
 */
final class FormatosOcr
{
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
