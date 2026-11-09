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

/*
 * Conjunto do total e situação calculada (DP-01; RF-03, RF-05, RNF-03).
 */

it('as constantes do mimetype não informado e da situação ignorado têm os valores de RF-03', function () {
    expect(FormatosOcr::MIMETYPE_NAO_INFORMADO)->toBe('')
        ->and(FormatosOcr::SITUACAO_IGNORADO)->toBe('ignorado');
});

it('situacaoNaLista dá o resultado da tabela de RF-03', function (string $mimetype, ?string $situacao, string $esperada) {
    expect(FormatosOcr::situacaoNaLista($mimetype, $situacao))->toBe($esperada);
})->with([
    'video/mp4 / null → ignorado' => ['video/mp4', null, 'ignorado'],
    'application/pdf / null → pendente' => ['application/pdf', null, 'pendente'],
    "'' / null → pendente" => ['', null, 'pendente'],
    'application/pdf / processado → processado' => ['application/pdf', 'processado', 'processado'],
    'text/html / enviado → enviado' => ['text/html', 'enviado', 'enviado'],
    'image/png / erro → erro' => ['image/png', 'erro', 'erro'],
    "'' / erro → erro" => ['', 'erro', 'erro'],
    'audio/mpeg / null → ignorado' => ['audio/mpeg', null, 'ignorado'],
    'application/zip / pendente → ignorado' => ['application/zip', 'pendente', 'ignorado'],
    'video/mp4 / processado → ignorado' => ['video/mp4', 'processado', 'ignorado'],
]);

it('situacaoNaLista sempre dá 1 dos 5 valores, nunca null, e o vazio nunca sai ignorado', function () {
    $validos = ['pendente', 'enviado', 'processado', 'erro', 'ignorado'];
    $mimetypes = [...FormatosOcr::MIMETYPES_LEGIVEIS, '', 'video/mp4', 'audio/mpeg', 'application/zip', null];
    $situacoes = [null, 'pendente', 'enviado', 'processado', 'erro', 'desconhecida', ''];

    foreach ($mimetypes as $mimetype) {
        foreach ($situacoes as $situacao) {
            $resultado = FormatosOcr::situacaoNaLista($mimetype, $situacao);

            expect($resultado)->not->toBeNull()->toBeIn($validos);

            if ($mimetype === '') {
                expect($resultado)->not->toBe(FormatosOcr::SITUACAO_IGNORADO);
            }
        }
    }

    expect(FormatosOcr::situacaoNaLista('', null))->toBe('pendente')
        ->and(FormatosOcr::situacaoNaLista('', 'erro'))->toBe('erro');
});

it('situacaoNaLista leva situação desconhecida de mimetype do total a pendente, como a contagem', function (?string $situacao) {
    expect(FormatosOcr::situacaoNaLista('application/pdf', $situacao))->toBe('pendente')
        ->and(FormatosOcr::situacaoNaLista('', $situacao))->toBe('pendente');
})->with([
    'nulo' => [null],
    'pendente' => ['pendente'],
    'valor fora das 4 situações' => ['desconhecida'],
    'vazio' => [''],
]);

it('entraNoTotal vale só para os 8 legíveis e o vazio, e ehIgnorado é a negação', function (string $mimetype, bool $noTotal) {
    expect(FormatosOcr::entraNoTotal($mimetype))->toBe($noTotal)
        ->and(FormatosOcr::ehIgnorado($mimetype))->toBe(! $noTotal);
})->with([
    'application/pdf' => ['application/pdf', true],
    'text/html' => ['text/html', true],
    'image/png' => ['image/png', true],
    'image/jpeg' => ['image/jpeg', true],
    'image/webp' => ['image/webp', true],
    'image/tiff' => ['image/tiff', true],
    'image/bmp' => ['image/bmp', true],
    'image/gif' => ['image/gif', true],
    'vazio' => ['', true],
    'video/mp4' => ['video/mp4', false],
    'audio/mpeg' => ['audio/mpeg', false],
    'application/zip' => ['application/zip', false],
]);

it('o nulo fica fora do total e conta como ignorado, como o ELSE da contagem', function () {
    expect(FormatosOcr::entraNoTotal(null))->toBeFalse()
        ->and(FormatosOcr::ehIgnorado(null))->toBeTrue()
        ->and(FormatosOcr::situacaoNaLista(null, null))->toBe('ignorado');
});

it('o vazio entra no total, mas continua não legível e sem extensão de envio', function () {
    expect(FormatosOcr::entraNoTotal(''))->toBeTrue()
        ->and(FormatosOcr::ehLegivel(''))->toBeFalse()
        ->and(fn () => FormatosOcr::extensaoDoFilename(''))->toThrow(InvalidArgumentException::class);
});

it('a lista legível é declarada num só arquivo de app/ (RNF-03)', function () {
    $arquivos = [];

    $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($iterador as $arquivo) {
        if ($arquivo->isFile() && str_contains((string) file_get_contents($arquivo->getPathname()), "'image/webp'")) {
            $arquivos[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $arquivo->getPathname());
        }
    }

    expect($arquivos)->toBe(['app/Services/Ocr/FormatosOcr.php']);
});
