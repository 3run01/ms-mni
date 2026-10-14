<?php

use App\Services\MNI\NormalizarDocumentoMniService;

function documentoMniNormalizador(int|string $id, array $extras = []): object
{
    return (object) array_merge([
        'idDocumento' => $id,
        'mimetype' => 'application/pdf',
        'conteudo' => 'conteudo-' . $id,
    ], $extras);
}

function idsDosDocumentosNormalizador(array $documentos): array
{
    return array_map(fn ($d) => (string) $d->idDocumento, $documentos);
}

/**
 * Regra de ConsultarDocumentoService.php:67-83, copiada como referência
 * para provar que o normalizador produz o mesmo resultado.
 */
function normalizarComoConsultarDocumento(object $documento): object
{
    $documento_retorno = clone $documento;

    if ($documento_retorno->mimetype == 'application/pdf') {
        $documento_retorno->conteudo = base64_encode($documento_retorno->conteudo);
    } else if (in_array($documento_retorno->mimetype, ['video/mp4', 'video/quicktime', 'audio/mpeg', 'audio/mp3'])) {
        $isBase64 = false;
        if (is_string($documento_retorno->conteudo)) {
            $decoded = base64_decode($documento_retorno->conteudo, true);
            if ($decoded !== false && base64_encode($decoded) === $documento_retorno->conteudo) {
                $isBase64 = true;
            }
        }

        if (!$isBase64) {
            $documento_retorno->conteudo = base64_encode($documento_retorno->conteudo);
        }
    } else {
        $documento_retorno->conteudo = base64_encode($documento_retorno->conteudo);
    }

    return $documento_retorno;
}

// extrairDocumentos ----------------------------------------------------------

it('extrai documento unico como objeto', function () {
    $processo = (object) ['documento' => documentoMniNormalizador(1)];

    expect(idsDosDocumentosNormalizador(NormalizarDocumentoMniService::extrairDocumentos($processo)))
        ->toBe(['1']);
});

it('extrai varios documentos vindos como array', function () {
    $processo = (object) ['documento' => [documentoMniNormalizador(1), documentoMniNormalizador(2)]];

    expect(idsDosDocumentosNormalizador(NormalizarDocumentoMniService::extrairDocumentos($processo)))
        ->toBe(['1', '2']);
});

it('extrai documentos filhos diretos de processos (amostra do consultarProcessos)', function () {
    $retorno = (object) [
        'sucesso' => true,
        'processos' => (object) ['documento' => [documentoMniNormalizador(1), documentoMniNormalizador(2)]],
    ];

    expect(idsDosDocumentosNormalizador(NormalizarDocumentoMniService::extrairDocumentos($retorno->processos)))
        ->toBe(['1', '2'])
        ->and(idsDosDocumentosNormalizador(NormalizarDocumentoMniService::extrairDocumentos($retorno)))
        ->toBe(['1', '2']);
});

it('extrai documentos de processos->processo->documento', function () {
    $processos = (object) [
        'processo' => (object) ['documento' => [documentoMniNormalizador(1), documentoMniNormalizador(2)]],
    ];

    expect(idsDosDocumentosNormalizador(NormalizarDocumentoMniService::extrairDocumentos($processos)))
        ->toBe(['1', '2']);
});

it('extrai documentos de processos com varios processo em array', function () {
    $processos = (object) [
        'processo' => [
            (object) ['documento' => documentoMniNormalizador(1)],
            (object) ['documento' => documentoMniNormalizador(2)],
        ],
    ];

    expect(idsDosDocumentosNormalizador(NormalizarDocumentoMniService::extrairDocumentos($processos)))
        ->toBe(['1', '2']);
});

it('extrai documentos do processo da consulta unitaria', function () {
    $retorno = (object) [
        'sucesso' => true,
        'processo' => (object) ['documento' => documentoMniNormalizador(1)],
    ];

    expect(idsDosDocumentosNormalizador(NormalizarDocumentoMniService::extrairDocumentos($retorno->processo)))
        ->toBe(['1']);
});

it('inclui documentoVinculado unico e em array logo apos o principal', function () {
    $processo = (object) ['documento' => [
        documentoMniNormalizador(1, ['documentoVinculado' => documentoMniNormalizador(11)]),
        documentoMniNormalizador(2, ['documentoVinculado' => [documentoMniNormalizador(21), documentoMniNormalizador(22)]]),
    ]];

    expect(idsDosDocumentosNormalizador(NormalizarDocumentoMniService::extrairDocumentos($processo)))
        ->toBe(['1', '11', '2', '21', '22']);
});

it('devolve lista vazia sem documentos', function (mixed $container) {
    expect(NormalizarDocumentoMniService::extrairDocumentos($container))->toBe([]);
})->with([
    'nulo' => [null],
    'processos sem documento' => [(object) ['pagina' => 2, 'totalRegistros' => 1]],
    'documento vazio' => [(object) ['documento' => []]],
    'string' => ['texto'],
]);

// indexarPorId ---------------------------------------------------------------

it('indexa por idDocumento como string para objeto, array e vinculado', function () {
    $processo = (object) ['documento' => [
        documentoMniNormalizador(100, ['documentoVinculado' => documentoMniNormalizador('101')]),
        documentoMniNormalizador('200'),
    ]];

    $mapa = NormalizarDocumentoMniService::indexarPorId(
        NormalizarDocumentoMniService::extrairDocumentos($processo)
    );

    expect($mapa)->toHaveCount(3)
        ->toHaveKeys(['100', '101', '200'])
        ->and($mapa[(string) 100]->conteudo)->toBe('conteudo-100')
        ->and($mapa['101']->conteudo)->toBe('conteudo-101')
        ->and($mapa[(string) '200']->conteudo)->toBe('conteudo-200');
});

it('casa id inteiro com string e nao confunde zeros a esquerda', function () {
    $mapa = NormalizarDocumentoMniService::indexarPorId([
        documentoMniNormalizador(123),
        documentoMniNormalizador('0123'),
    ]);

    expect($mapa)->toHaveCount(2)
        ->and($mapa[(string) 123]->idDocumento)->toBe(123)
        ->and($mapa['0123']->idDocumento)->toBe('0123');
});

it('faz a ultima ocorrencia do mesmo id vencer', function () {
    $processo = (object) ['documento' => [
        documentoMniNormalizador(1, ['conteudo' => 'primeiro']),
        documentoMniNormalizador(2, ['documentoVinculado' => documentoMniNormalizador(1, ['conteudo' => 'vinculado'])]),
    ]];

    $mapa = NormalizarDocumentoMniService::indexarPorId(
        NormalizarDocumentoMniService::extrairDocumentos($processo)
    );

    expect($mapa['1']->conteudo)->toBe('vinculado');
});

it('ignora entradas sem idDocumento', function () {
    $mapa = NormalizarDocumentoMniService::indexarPorId([
        (object) ['conteudo' => 'sem id'],
        documentoMniNormalizador(5),
    ]);

    expect(array_keys($mapa))->toBe([5]);
});

// classificar ----------------------------------------------------------------

it('classifica conteudo ausente, nulo ou vazio como vazio', function (object $documento) {
    expect(NormalizarDocumentoMniService::classificar($documento))
        ->toBe(NormalizarDocumentoMniService::CLASSIFICACAO_VAZIO)
        ->toBe('vazio');
})->with([
    'ausente' => [(object) ['idDocumento' => 1]],
    'nulo' => [(object) ['idDocumento' => 1, 'conteudo' => null]],
    'string vazia' => [(object) ['idDocumento' => 1, 'conteudo' => '']],
]);

it('classifica o texto de bloqueio do MNI como bloqueado', function () {
    $documento = documentoMniNormalizador(1, [
        'conteudo' => 'Visualização do documento bloqueada enquanto não houver ciência de seus destinatários',
    ]);

    expect(NormalizarDocumentoMniService::classificar($documento))
        ->toBe(NormalizarDocumentoMniService::CLASSIFICACAO_BLOQUEADO)
        ->toBe('bloqueado');
});

it('classifica como ok o conteudo que so contem o texto de bloqueio', function () {
    $documento = documentoMniNormalizador(1, [
        'conteudo' => '<p>Visualização do documento bloqueada enquanto não houver ciência de seus destinatários</p>',
    ]);

    expect(NormalizarDocumentoMniService::classificar($documento))->toBe('ok');
});

it('classifica conteudo preenchido como ok', function () {
    expect(NormalizarDocumentoMniService::classificar(documentoMniNormalizador(1, ['conteudo' => '%PDF-1.4'])))
        ->toBe(NormalizarDocumentoMniService::CLASSIFICACAO_OK)
        ->toBe('ok');
});

// normalizarConteudo ---------------------------------------------------------

it('normaliza o conteudo igual a ConsultarDocumentoService', function (string $mimetype, string $conteudo, string $esperado) {
    $documento = documentoMniNormalizador(1, ['mimetype' => $mimetype, 'conteudo' => $conteudo]);

    $normalizado = NormalizarDocumentoMniService::normalizarConteudo($documento);

    expect($normalizado->conteudo)->toBe($esperado)
        ->and($normalizado->conteudo)->toBe(normalizarComoConsultarDocumento($documento)->conteudo);
})->with([
    'pdf cru' => ['application/pdf', "%PDF-1.4\n\x00\xff", base64_encode("%PDF-1.4\n\x00\xff")],
    'pdf que parece base64 e codificado de novo' => ['application/pdf', base64_encode('%PDF'), base64_encode(base64_encode('%PDF'))],
    'audio/mpeg ja em base64' => ['audio/mpeg', base64_encode("\xff\xfb\x90\x00"), base64_encode("\xff\xfb\x90\x00")],
    'audio/mpeg cru' => ['audio/mpeg', "\xff\xfb\x90\x00ID3", base64_encode("\xff\xfb\x90\x00ID3")],
    'audio/mp3 ja em base64' => ['audio/mp3', base64_encode('ID3-audio'), base64_encode('ID3-audio')],
    'video/mp4 ja em base64' => ['video/mp4', base64_encode('ftypmp42'), base64_encode('ftypmp42')],
    'video/quicktime cru' => ['video/quicktime', "\x00\x00\x00\x14ftypqt  ", base64_encode("\x00\x00\x00\x14ftypqt  ")],
    'text/html' => ['text/html', '<html><body>Sentenca</body></html>', base64_encode('<html><body>Sentenca</body></html>')],
    'text/html que parece base64' => ['text/html', base64_encode('<p>x</p>'), base64_encode(base64_encode('<p>x</p>'))],
    'mimetype desconhecido' => ['application/x-desconhecido', 'bytes', base64_encode('bytes')],
]);

it('nao altera o documento recebido', function () {
    $documento = documentoMniNormalizador(1, ['mimetype' => 'text/html', 'conteudo' => '<p>original</p>']);

    $normalizado = NormalizarDocumentoMniService::normalizarConteudo($documento);

    expect($documento->conteudo)->toBe('<p>original</p>')
        ->and($normalizado)->not->toBe($documento)
        ->and($normalizado->idDocumento)->toBe(1)
        ->and($normalizado->mimetype)->toBe('text/html');
});

it('normaliza conteudo ausente como string vazia', function () {
    $normalizado = NormalizarDocumentoMniService::normalizarConteudo((object) ['idDocumento' => 1, 'mimetype' => 'audio/mpeg']);

    expect($normalizado->conteudo)->toBe('');
});
