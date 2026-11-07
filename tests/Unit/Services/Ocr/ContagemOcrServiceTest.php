<?php

use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Services\Ocr\ContagemOcr;
use App\Services\Ocr\ContagemOcrService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

/**
 * Cria no processo um documento por item de `$documentos`: `[mimetype, ocr_situacao]`.
 * A coluna `mimetype` é NOT NULL: o "sem mimetype" é a string vazia.
 *
 * @param  list<array{0: string, 1: ?string}>  $documentos
 */
function processoContagemOcr(array $documentos, ?Processo $processo = null): Processo
{
    [$processo] = criarDocumentosLote(
        array_map(fn (array $documento) => [
            'mimetype' => $documento[0],
            'ocr_situacao' => $documento[1],
        ], $documentos),
        $processo ?? [],
    );

    return $processo;
}

/**
 * Invariantes de RF-08 em toda contagem: `processados + com_erro ≤ total`,
 * `total + ignorados` = documentos do processo no banco e cada legível numa
 * só situação.
 */
function expectInvariantesContagemOcr(ContagemOcr $contagem, Processo $processo): void
{
    $documentosNoBanco = ProcessoDocumento::where('processo_id', $processo->id)->count();

    expect($contagem->processados + $contagem->comErro)->toBeLessThanOrEqual($contagem->total)
        ->and($contagem->total + $contagem->ignorados)->toBe($documentosNoBanco)
        ->and($contagem->processados + $contagem->comErro + $contagem->pendentes + $contagem->aguardando)
        ->toBe($contagem->total);
}

it('3 PDF, 1 HTML, 1 PNG, 1 MP4 e 1 MP3 dão total 5 e ignorados 2, todos pendentes', function () {
    $processo = processoContagemOcr([
        ['application/pdf', null],
        ['application/pdf', null],
        ['application/pdf', null],
        ['text/html', null],
        ['image/png', null],
        ['video/mp4', null],
        ['audio/mpeg', null],
    ]);

    $contagem = app(ContagemOcrService::class)->contar($processo->id);

    expect($contagem->total)->toBe(5)
        ->and($contagem->ignorados)->toBe(2)
        ->and($contagem->processados)->toBe(0)
        ->and($contagem->comErro)->toBe(0)
        ->and($contagem->pendentes)->toBe(5)
        ->and($contagem->aguardando)->toBe(0)
        ->and($contagem->paraCorpo())->toBe([
            'total' => 5,
            'processados' => 0,
            'com_erro' => 0,
            'ignorados' => 2,
        ]);

    expectInvariantesContagemOcr($contagem, $processo);
});

it('ocr_situacao nulo conta como pendente, assim como pendente', function () {
    $processo = processoContagemOcr([
        ['application/pdf', null],
        ['application/pdf', ProcessoDocumento::OCR_PENDENTE],
    ]);

    $contagem = app(ContagemOcrService::class)->contar($processo->id);

    expect($contagem->pendentes)->toBe(2)
        ->and($contagem->total)->toBe(2)
        ->and($contagem->processados)->toBe(0)
        ->and($contagem->comErro)->toBe(0)
        ->and($contagem->aguardando)->toBe(0);
});

it('separa processados, com_erro, aguardando e pendentes entre os legíveis', function () {
    $processo = processoContagemOcr([
        ['application/pdf', ProcessoDocumento::OCR_PROCESSADO],
        ['image/jpeg', ProcessoDocumento::OCR_PROCESSADO],
        ['text/html', ProcessoDocumento::OCR_ERRO],
        ['image/tiff', ProcessoDocumento::OCR_ENVIADO],
        ['image/gif', ProcessoDocumento::OCR_ENVIADO],
        ['image/webp', ProcessoDocumento::OCR_PENDENTE],
        ['image/bmp', null],
    ]);

    $contagem = app(ContagemOcrService::class)->contar($processo->id);

    expect($contagem->total)->toBe(7)
        ->and($contagem->processados)->toBe(2)
        ->and($contagem->comErro)->toBe(1)
        ->and($contagem->aguardando)->toBe(2)
        ->and($contagem->pendentes)->toBe(2)
        ->and($contagem->ignorados)->toBe(0);

    expectInvariantesContagemOcr($contagem, $processo);
});

it('situação do OCR em documento não legível não entra nas contagens dos legíveis', function () {
    $processo = processoContagemOcr([
        ['application/pdf', ProcessoDocumento::OCR_PROCESSADO],
        ['video/mp4', ProcessoDocumento::OCR_PROCESSADO],
        ['audio/mpeg', ProcessoDocumento::OCR_ERRO],
        ['video/quicktime', ProcessoDocumento::OCR_ENVIADO],
        ['', null],
    ]);

    $contagem = app(ContagemOcrService::class)->contar($processo->id);

    expect($contagem->total)->toBe(1)
        ->and($contagem->processados)->toBe(1)
        ->and($contagem->comErro)->toBe(0)
        ->and($contagem->aguardando)->toBe(0)
        ->and($contagem->pendentes)->toBe(0)
        ->and($contagem->ignorados)->toBe(4);

    expectInvariantesContagemOcr($contagem, $processo);
});

it('conta só os documentos do processo informado', function () {
    $processo = processoContagemOcr([
        ['application/pdf', ProcessoDocumento::OCR_PROCESSADO],
        ['video/mp4', null],
    ]);
    processoContagemOcr([
        ['application/pdf', null],
        ['application/pdf', ProcessoDocumento::OCR_ERRO],
        ['audio/mpeg', null],
    ]);

    $contagem = app(ContagemOcrService::class)->contar($processo->id);

    expect($contagem->paraCorpo())->toBe([
        'total' => 1,
        'processados' => 1,
        'com_erro' => 0,
        'ignorados' => 1,
    ]);

    expectInvariantesContagemOcr($contagem, $processo);
});

it('processo sem documentos dá tudo zero', function () {
    $processo = Processo::factory()->create();

    $contagem = app(ContagemOcrService::class)->contar($processo->id);

    expect($contagem)->toEqual(new ContagemOcr(0, 0, 0, 0, 0, 0))
        ->and($contagem->paraCorpo())->toBe([
            'total' => 0,
            'processados' => 0,
            'com_erro' => 0,
            'ignorados' => 0,
        ]);

    expectInvariantesContagemOcr($contagem, $processo);
});

it('mantém as invariantes de RF-08 em todo cenário', function (array $documentos) {
    $processo = processoContagemOcr($documentos);

    expectInvariantesContagemOcr(app(ContagemOcrService::class)->contar($processo->id), $processo);
})->with([
    'só ignorados' => [[['video/mp4', null], ['audio/mpeg', null]]],
    'todos processados' => [[
        ['application/pdf', ProcessoDocumento::OCR_PROCESSADO],
        ['text/html', ProcessoDocumento::OCR_PROCESSADO],
        ['image/png', ProcessoDocumento::OCR_PROCESSADO],
    ]],
    'todos com erro' => [[
        ['application/pdf', ProcessoDocumento::OCR_ERRO],
        ['image/gif', ProcessoDocumento::OCR_ERRO],
    ]],
    'misto com ignorados' => [[
        ['application/pdf', ProcessoDocumento::OCR_PROCESSADO],
        ['application/pdf', ProcessoDocumento::OCR_ERRO],
        ['image/png', ProcessoDocumento::OCR_ENVIADO],
        ['image/jpeg', null],
        ['video/mp4', ProcessoDocumento::OCR_PROCESSADO],
        ['audio/mpeg', null],
        ['', null],
    ]],
]);

it('faz uma consulta só ao banco', function () {
    $processo = processoContagemOcr([
        ['application/pdf', ProcessoDocumento::OCR_PROCESSADO],
        ['video/mp4', null],
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(ContagemOcrService::class)->contar($processo->id);

    $consultas = DB::getQueryLog();
    DB::disableQueryLog();

    expect($consultas)->toHaveCount(1)
        ->and($consultas[0]['query'])->toContain('processo_documentos');
});

it('paraCorpo devolve só as 4 chaves do contrato', function () {
    $corpo = (new ContagemOcr(10, 4, 1, 2, 3, 2))->paraCorpo();

    expect(array_keys($corpo))->toBe(['total', 'processados', 'com_erro', 'ignorados'])
        ->and($corpo)->toBe(['total' => 10, 'processados' => 4, 'com_erro' => 1, 'ignorados' => 2]);
});
