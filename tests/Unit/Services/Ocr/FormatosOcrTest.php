<?php

use App\Services\Ocr\FormatosOcr;

it('os mimetypes legíveis são exatamente PDF, HTML e as 6 imagens do workflow', function () {
    expect(FormatosOcr::MIMETYPES_LEGIVEIS)->toEqualCanonicalizing([
        'application/pdf',
        'text/html',
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/tiff',
        'image/bmp',
        'image/gif',
    ])->and(FormatosOcr::MIMETYPES_LEGIVEIS)->toHaveCount(8);
});

it('ehLegivel aceita cada mimetype legível', function (string $mimetype) {
    expect(FormatosOcr::ehLegivel($mimetype))->toBeTrue();
})->with(FormatosOcr::MIMETYPES_LEGIVEIS);

it('ehLegivel recusa áudio, vídeo, outros formatos e mimetype ausente', function (?string $mimetype) {
    expect(FormatosOcr::ehLegivel($mimetype))->toBeFalse();
})->with([
    'video/mp4' => ['video/mp4'],
    'video/quicktime' => ['video/quicktime'],
    'audio/mpeg' => ['audio/mpeg'],
    'audio/ogg' => ['audio/ogg'],
    'image/svg+xml' => ['image/svg+xml'],
    'application/msword' => ['application/msword'],
    'text/plain' => ['text/plain'],
    'vazio' => [''],
    'nulo' => [null],
    'maiúsculas não normalizadas' => ['APPLICATION/PDF'],
]);

it('extensaoDoFilename dá a extensão de RF-11 para cada mimetype legível', function (string $mimetype, string $extensao) {
    expect(FormatosOcr::extensaoDoFilename($mimetype))->toBe($extensao);
})->with([
    'pdf' => ['application/pdf', 'pdf'],
    'html vai pelo PDF renderizado' => ['text/html', 'pdf'],
    'png' => ['image/png', 'png'],
    'jpeg' => ['image/jpeg', 'jpg'],
    'webp' => ['image/webp', 'webp'],
    'tiff' => ['image/tiff', 'tif'],
    'bmp' => ['image/bmp', 'bmp'],
    'gif' => ['image/gif', 'gif'],
]);

it('toda extensão casa com o filename que o workflow aceita', function (string $mimetype) {
    $filename = '930001.'.FormatosOcr::extensaoDoFilename($mimetype);

    // pattern de EnvioDeDocumentoAoOcr.filename em workflow-ocr.openapi.yaml
    expect($filename)->toMatch('/^[0-9]+\.(pdf|png|jpg|webp|tif|bmp|gif)$/');
})->with(FormatosOcr::MIMETYPES_LEGIVEIS);

it('as 6 imagens não vão como .pdf nem como .dat do objeto no S3', function () {
    $imagens = array_values(array_filter(
        FormatosOcr::MIMETYPES_LEGIVEIS,
        fn (string $mimetype) => str_starts_with($mimetype, 'image/'),
    ));

    expect($imagens)->toHaveCount(6);

    foreach ($imagens as $mimetype) {
        expect(FormatosOcr::extensaoDoFilename($mimetype))->not->toBeIn(['pdf', 'dat']);
    }
});

it('extensaoDoFilename recusa mimetype que o OCR não lê', function (string $mimetype) {
    FormatosOcr::extensaoDoFilename($mimetype);
})->with(['video/mp4', 'audio/mpeg', 'image/svg+xml', ''])
    ->throws(InvalidArgumentException::class);
