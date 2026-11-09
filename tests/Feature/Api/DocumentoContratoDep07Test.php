<?php

use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\Tribunal;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * Contrato do documento fora de /processo/visualizar (T06; RF-04, RF-06,
 * CT-02). Só testes: o comportamento de DocumentoController::show
 * (anexarConteudoHtml) e de listarDocumentos não muda.
 *
 * - /documento/visualizar mantém `conteudo_html` sempre presente: o HTML no
 *   `text/html`, `null` nos demais. Num `text/html`, `link` é a URL
 *   temporária do PDF renderizado (o objeto de `path`).
 * - A situação calculada do OCR só existe em /processo/visualizar: nenhum
 *   campo `ocr_*` sai em /documento/visualizar nem em
 *   /processo/documentos/listar, com o processo no banco e fora dele.
 * - A leitura não grava `conteudo_html` nem `ocr_*` no banco.
 */

uses(DatabaseTransactions::class);

const LOGIN_CONTRATO_DEP07 = 'login-contrato-dep07';
const SENHA_CONTRATO_DEP07 = 'senha-contrato-dep07';

/** Colunas que a leitura nunca grava. */
const COLUNAS_INTOCADAS_CONTRATO_DEP07 = [
    'conteudo_html',
    'ocr_situacao',
    'ocr_job_id',
    'ocr_job_id_cancelar',
    'ocr_enviado_em',
    'ocr_concluido_em',
    'ocr_erro',
];

beforeEach(function () {
    criarTokenApi();
    // isola do .env da máquina: as chamadas levam o par do cliente
    definirCredenciaisPadrao(null, null);
    // a consulta do processo fora do banco enfileira o download em lote
    Queue::fake();
    Storage::fake('s3');
    Storage::disk('s3')->buildTemporaryUrlsUsing(
        fn ($path, $expiration, $options = []) => 'https://s3.fake/' . $path
    );
});

function tribunalContratoDep07(): Tribunal
{
    return Tribunal::factory()->create([
        'login' => 'login-do-tribunal',
        'password' => Crypt::encrypt('{SENHA}'),
        'ativo' => true,
    ]);
}

/**
 * Documento `baixado` com todos os campos `ocr_*` gravados.
 */
function documentoComOcrContratoDep07(Processo $processo, int $idDocumento, array $atributos = []): ProcessoDocumento
{
    return ProcessoDocumento::create(array_merge([
        'processo_id' => $processo->id,
        'id_documento' => $idDocumento,
        'tipo_documento' => '57',
        'data_hora' => '2026-03-06 10:00:00',
        'descricao' => 'Documento do contrato DEP-07',
        'mimetype' => 'application/pdf',
        'status' => ProcessoDocumento::STATUS_BAIXADO,
        'path' => "documentos-processos/{$processo->numero_processo}/{$idDocumento}.pdf",
        'tentativas_download' => 0,
        'ocr_situacao' => ProcessoDocumento::OCR_PROCESSADO,
        'ocr_job_id' => "job-{$idDocumento}",
        'ocr_job_id_cancelar' => "cancelar-{$idDocumento}",
        'ocr_enviado_em' => '2026-03-06 10:05:00',
        'ocr_concluido_em' => '2026-03-06 10:07:00',
        'ocr_erro' => 'erro antigo do OCR',
    ], $atributos));
}

/**
 * As colunas intocadas, cruas do banco, para comparar antes e depois.
 *
 * @return array<string, mixed>
 */
function colunasIntocadasContratoDep07(ProcessoDocumento $documento): array
{
    return (array) DB::table('processo_documentos')
        ->where('id', $documento->id)
        ->select(COLUNAS_INTOCADAS_CONTRATO_DEP07)
        ->sole();
}

/**
 * @param  array<string, mixed>  $item
 * @return array<int, string>
 */
function chavesOcrContratoDep07(array $item): array
{
    return array_values(array_filter(
        array_keys($item),
        fn (string $chave) => str_starts_with($chave, 'ocr_'),
    ));
}

function visualizarDocumentoContratoDep07(int $tribunalId, string $numero, int $idDocumento): TestResponse
{
    return test()->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/documento/visualizar?' . http_build_query([
            'tribunal_id' => $tribunalId,
            'numero_processo' => $numero,
            'id_documento' => $idDocumento,
            'login_pje' => LOGIN_CONTRATO_DEP07,
            'senha_pje' => SENHA_CONTRATO_DEP07,
        ]));
}

function listarDocumentosContratoDep07(int $tribunalId, string $numero): TestResponse
{
    return test()->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/documentos/listar?' . http_build_query([
            'tribunal_id' => $tribunalId,
            'numero_processo' => $numero,
            'login_pje' => LOGIN_CONTRATO_DEP07,
            'senha_pje' => SENHA_CONTRATO_DEP07,
        ]));
}

/**
 * MNI simulado do processo fora do banco: a consulta do processo devolve
 * os dados básicos e a lista com 1 PDF (sem conteúdo, como a lista real), e
 * a consulta do documento devolve o binário.
 */
function mniProcessoForaDoBancoContratoDep07(string $numero, int $idDocumento): stdClass
{
    $documentoDaLista = documentoMniFake($idDocumento, ['mimetype' => 'application/pdf']);
    unset($documentoDaLista->conteudo);

    return simularIntegracaoMni(function (string $operacao, array $parametros) use ($numero, $idDocumento, $documentoDaLista) {
        if (array_key_exists('documento', $parametros)) {
            return (object) [
                'sucesso' => true,
                'processo' => (object) ['documento' => documentoMniFake($idDocumento, ['mimetype' => 'application/pdf'])],
            ];
        }

        return (object) [
            'sucesso' => true,
            'processo' => (object) [
                'dadosBasicos' => (object) [
                    'numero' => $numero,
                    'codigoLocalidade' => '1',
                    'classeProcessual' => '7',
                    'nivelSigilo' => 0,
                    'valorCausa' => 0,
                    'polo' => [],
                ],
                'movimento' => [],
                'documento' => [$documentoDaLista],
            ],
        ];
    });
}

// ---------- RF-06: conteudo_html e link em /documento/visualizar ----------

it('HTML baixado com o .pdf e o .html no S3: 200, conteudo_html igual ao .html e link para o .pdf', function () {
    $tribunal = tribunalContratoDep07();
    $processo = Processo::factory()->create(['tribunal_id' => $tribunal->id, 'numero_processo' => '99998071320268030001']);
    $pdf = "documentos-processos/{$processo->numero_processo}/9807101.pdf";
    $html = "documentos-processos/{$processo->numero_processo}/9807101.html";
    $conteudoHtml = '<html><body><p>Sentença do contrato DEP-07</p></body></html>';
    $documento = documentoComOcrContratoDep07($processo, 9807101, [
        'mimetype' => 'text/html',
        'path' => $pdf,
        'path_html' => $html,
    ]);
    Storage::disk('s3')->put($pdf, 'pdf-renderizado');
    Storage::disk('s3')->put($html, $conteudoHtml);
    $antes = colunasIntocadasContratoDep07($documento);
    $simulador = simularIntegracaoMni(fn () => throw new LogicException('o MNI não deveria ser chamado'));

    $response = visualizarDocumentoContratoDep07($tribunal->id, $processo->numero_processo, 9807101)
        ->assertOk()
        ->assertJsonPath('documento.conteudo_html', $conteudoHtml)
        ->assertJsonPath('documento.link', 'https://s3.fake/' . $pdf);

    expect($response->json('documento.link'))->toEndWith('.pdf')
        ->and(chavesOcrContratoDep07($response->json('documento')))->toBe([])
        ->and(colunasIntocadasContratoDep07($documento))->toBe($antes)
        ->and($antes['conteudo_html'])->toBeNull()
        ->and($simulador->requisicoes)->toBe([]);
});

it('PDF: 200, com a chave conteudo_html presente e nula', function () {
    $tribunal = tribunalContratoDep07();
    $processo = Processo::factory()->create(['tribunal_id' => $tribunal->id, 'numero_processo' => '99998071420268030001']);
    $documento = documentoComOcrContratoDep07($processo, 9807201);
    Storage::disk('s3')->put($documento->path, 'pdf-fake');
    $antes = colunasIntocadasContratoDep07($documento);

    $response = visualizarDocumentoContratoDep07($tribunal->id, $processo->numero_processo, 9807201)
        ->assertOk()
        ->assertJsonPath('documento.link', 'https://s3.fake/' . $documento->path);

    expect($response->json('documento'))->toHaveKey('conteudo_html')
        ->and($response->json('documento.conteudo_html'))->toBeNull()
        ->and(chavesOcrContratoDep07($response->json('documento')))->toBe([])
        ->and(colunasIntocadasContratoDep07($documento))->toBe($antes);
});

// ---------- RF-04: nenhum ocr_* fora de /processo/visualizar ----------

it('/documento/visualizar com o processo fora do banco não tem chave ocr_ e não grava conteudo_html nem ocr_*', function () {
    $tribunal = tribunalContratoDep07();
    $numero = '99998071520268030001';
    $simulador = mniProcessoForaDoBancoContratoDep07($numero, 9807301);

    expect(Processo::where('tribunal_id', $tribunal->id)->where('numero_processo', $numero)->exists())->toBeFalse();

    $response = visualizarDocumentoContratoDep07($tribunal->id, $numero, 9807301)->assertOk();

    $documento = ProcessoDocumento::where('id_documento', 9807301)
        ->whereHas('processo', fn ($q) => $q->where('tribunal_id', $tribunal->id)->where('numero_processo', $numero))
        ->sole();

    expect($response->json('documento.id_documento'))->toBe(9807301)
        ->and($response->json('documento'))->toHaveKey('conteudo_html')
        ->and($response->json('documento.conteudo_html'))->toBeNull()
        ->and($response->json('documento.link'))->toBe('https://s3.fake/' . $documento->path)
        ->and(chavesOcrContratoDep07($response->json('documento')))->toBe([])
        ->and(colunasIntocadasContratoDep07($documento))->toBe(array_fill_keys(COLUNAS_INTOCADAS_CONTRATO_DEP07, null))
        // 1 consulta do processo e 1 do documento, ambas com o par da chamada
        ->and($simulador->requisicoes)->toHaveCount(2)
        ->and(collect($simulador->requisicoes)->pluck('parametros.idConsultante')->unique()->all())->toBe([LOGIN_CONTRATO_DEP07]);
});

it('/processo/documentos/listar com o processo no banco não tem chave ocr_ nos itens e não muda as colunas', function () {
    $tribunal = tribunalContratoDep07();
    $processo = Processo::factory()->create(['tribunal_id' => $tribunal->id, 'numero_processo' => '99998071620268030001']);
    $documentos = [
        documentoComOcrContratoDep07($processo, 9807401),
        documentoComOcrContratoDep07($processo, 9807402, [
            'mimetype' => 'text/html',
            'ocr_situacao' => ProcessoDocumento::OCR_ERRO,
        ]),
        documentoComOcrContratoDep07($processo, 9807403, [
            'mimetype' => 'video/mp4',
            'ocr_situacao' => ProcessoDocumento::OCR_ENVIADO,
        ]),
    ];
    $antes = array_map('colunasIntocadasContratoDep07', $documentos);
    $simulador = simularIntegracaoMni(fn () => throw new LogicException('o MNI não deveria ser chamado'));

    $itens = listarDocumentosContratoDep07($tribunal->id, $processo->numero_processo)
        ->assertOk()
        ->assertJsonCount(3)
        ->json();

    foreach ($itens as $item) {
        expect(chavesOcrContratoDep07($item))->toBe([])
            ->and($item)->not->toHaveKey('conteudo_html');
    }

    expect(collect($itens)->pluck('id_documento')->sort()->values()->all())->toBe([9807401, 9807402, 9807403])
        ->and(array_map('colunasIntocadasContratoDep07', $documentos))->toBe($antes)
        ->and($simulador->requisicoes)->toBe([]);
});

it('/processo/documentos/listar com o processo fora do banco não tem chave ocr_ nos itens e não grava ocr_*', function () {
    $tribunal = tribunalContratoDep07();
    $numero = '99998071720268030001';
    $simulador = mniProcessoForaDoBancoContratoDep07($numero, 9807501);

    $itens = listarDocumentosContratoDep07($tribunal->id, $numero)
        ->assertOk()
        ->assertJsonCount(1)
        ->json();

    $documento = ProcessoDocumento::where('id_documento', 9807501)
        ->whereHas('processo', fn ($q) => $q->where('tribunal_id', $tribunal->id)->where('numero_processo', $numero))
        ->sole();

    expect($itens[0]['id_documento'])->toBe(9807501)
        ->and(chavesOcrContratoDep07($itens[0]))->toBe([])
        ->and($itens[0])->not->toHaveKey('conteudo_html')
        ->and(colunasIntocadasContratoDep07($documento))->toBe(array_fill_keys(COLUNAS_INTOCADAS_CONTRATO_DEP07, null))
        ->and($simulador->requisicoes)->not->toBeEmpty();
});
