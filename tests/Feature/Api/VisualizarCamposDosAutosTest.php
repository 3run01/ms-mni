<?php

use App\Models\ApiToken;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\TipoDocumento;
use App\Models\Tribunal;
use App\Services\Ocr\ContagemOcrService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/*
 * Campos dos autos e situação do OCR em GET /processo/visualizar (T07;
 * RF-01, RF-02, RF-03, CT-01, RNF-02). Cada item de `documentos` sai com
 * exatamente 13 chaves, `tipo` resolvido numa consulta só e `ocr_situacao`
 * calculada pela regra da contagem, sem gravar nada.
 */

uses(DatabaseTransactions::class);

const NUMERO_CAMPOS_DOS_AUTOS = '99998081220268030001';
const LOGIN_CAMPOS_DOS_AUTOS = 'login-campos-dos-autos-3a9';
const SENHA_CAMPOS_DOS_AUTOS = 'senha-campos-dos-autos-7c2';

const CHAVES_DOCUMENTO_CAMPOS_DOS_AUTOS = [
    'id_documento',
    'descricao',
    'id_documento_vinculado',
    'movimento',
    'tipo_documento',
    'data_hora',
    'nivel_sigilo',
    'tipo',
    'data_juntada',
    'usuario_juntada_arquivo',
    'mimetype',
    'file_size',
    'ocr_situacao',
];

const CHAVES_PROIBIDAS_CAMPOS_DOS_AUTOS = [
    'status',
    'path',
    'path_html',
    'hash',
    'url',
    'link',
    'conteudo_html',
    'id',
    'processo_id',
    'ocr_job_id',
    'ocr_job_id_cancelar',
    'ocr_enviado_em',
    'ocr_concluido_em',
    'ocr_erro',
];

beforeEach(function () {
    criarTokenApi();
    definirCredenciaisPadrao(null, null);
    Queue::fake();
});

function processoCamposDosAutos(?Tribunal $tribunal = null, string $numero = NUMERO_CAMPOS_DOS_AUTOS): Processo
{
    $tribunal ??= Tribunal::factory()->create();

    return Processo::factory()->create([
        'tribunal_id' => $tribunal->id,
        'numero_processo' => $numero,
    ]);
}

function documentoCamposDosAutos(Processo $processo, int $idDocumento, array $atributos = []): ProcessoDocumento
{
    return ProcessoDocumento::create(array_merge([
        'processo_id' => $processo->id,
        'id_documento' => $idDocumento,
        'tipo_documento' => '57',
        'data_hora' => '2026-03-06 09:00:00',
        'descricao' => "Documento {$idDocumento}",
        'mimetype' => 'application/pdf',
        'status' => ProcessoDocumento::STATUS_PENDENTE,
        'tentativas_download' => 0,
    ], $atributos));
}

function tipoCamposDosAutos(int $tribunalId, string $codigo, string $descricao): TipoDocumento
{
    return TipoDocumento::create([
        'tribunal_id' => $tribunalId,
        'codigo' => $codigo,
        'descricao' => $descricao,
    ]);
}

function visualizarCamposDosAutos(int $tribunalId, string $numero = NUMERO_CAMPOS_DOS_AUTOS): TestResponse
{
    return test()->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/visualizar?' . http_build_query([
            'tribunal_id' => $tribunalId,
            'numero_processo' => $numero,
            'login_pje' => LOGIN_CAMPOS_DOS_AUTOS,
            'senha_pje' => SENHA_CAMPOS_DOS_AUTOS,
        ]));
}

/**
 * Os itens de `documentos` indexados pelo id_documento.
 *
 * @return array<int, array<string, mixed>>
 */
function documentosPorIdCamposDosAutos(TestResponse $response): array
{
    return collect($response->json('documentos'))
        ->keyBy(fn (array $item) => (int) $item['id_documento'])
        ->all();
}

function chavesOrdenadasCamposDosAutos(array $item): array
{
    $chaves = array_keys($item);
    sort($chaves);

    return $chaves;
}

function chavesEsperadasCamposDosAutos(): array
{
    $chaves = CHAVES_DOCUMENTO_CAMPOS_DOS_AUTOS;
    sort($chaves);

    return $chaves;
}

/**
 * MNI simulado do processo fora do banco: a lista traz 1 documento com a
 * data e o usuário da juntada nos `outroParametro`, como o PJe manda.
 */
function mniForaDoBancoCamposDosAutos(int $idDocumento, string $mimetype = 'application/pdf'): stdClass
{
    $documentoDaLista = documentoMniFake($idDocumento, [
        'mimetype' => $mimetype,
        'outroParametro' => [
            (object) ['nome' => 'pessoaJuntadaArquivo', 'valor' => 'LUCAS MONTEIRO PEREIRA'],
            (object) ['nome' => 'mni:pje:documento:dataJuntada', 'valor' => '1772791200000'],
        ],
    ]);
    unset($documentoDaLista->conteudo);

    return simularIntegracaoMni(fn () => (object) [
        'sucesso' => true,
        'processo' => (object) [
            'dadosBasicos' => (object) [
                'numero' => NUMERO_CAMPOS_DOS_AUTOS,
                'codigoLocalidade' => '1',
                'classeProcessual' => '7',
                'nivelSigilo' => 0,
                'valorCausa' => 0,
                'polo' => [],
            ],
            'movimento' => [],
            'documento' => [$documentoDaLista],
        ],
    ]);
}

// ---------- RF-01 / CT-01: as 13 chaves ----------

it('com o processo no banco, cada documento tem exatamente as 13 chaves e os 4 campos da coluna sem conversão', function () {
    $processo = processoCamposDosAutos();
    documentoCamposDosAutos($processo, 9808001, [
        'data_hora' => '2026-03-06 09:30:00',
        'data_juntada' => '2026-03-06 10:00:00',
        'usuario_juntada_arquivo' => 'LUCAS MONTEIRO PEREIRA',
        'mimetype' => 'application/pdf',
        'file_size' => 655360,
    ]);

    $response = visualizarCamposDosAutos($processo->tribunal_id)->assertOk();
    $item = $response->json('documentos.0');

    expect($response->json('documentos'))->toHaveCount(1)
        ->and(chavesOrdenadasCamposDosAutos($item))->toBe(chavesEsperadasCamposDosAutos())
        ->and($item['data_juntada'])->toBe('2026-03-06 10:00:00')
        ->and($item['usuario_juntada_arquivo'])->toBe('LUCAS MONTEIRO PEREIRA')
        ->and($item['mimetype'])->toBe('application/pdf')
        ->and($item['file_size'])->toBe(655360)
        // o mesmo formato de data_hora na mesma resposta
        ->and($item['data_hora'])->toBe('2026-03-06 09:30:00')
        ->and($item['ocr_situacao'])->toBe('pendente')
        ->and($item['tipo'])->toBeNull();

    // inteiro JSON, e não string
    expect($response->getContent())->toContain('"file_size":655360');
});

it('com o processo fora do banco (MNI fake), cada documento também tem exatamente as 13 chaves', function () {
    $tribunal = Tribunal::factory()->create();
    tipoCamposDosAutos($tribunal->id, '57', 'Certidão');
    $simulador = mniForaDoBancoCamposDosAutos(9808101);

    $response = visualizarCamposDosAutos($tribunal->id)->assertOk();
    $item = $response->json('documentos.0');

    expect($response->json('documentos'))->toHaveCount(1)
        ->and(collect($simulador->requisicoes)->where('operacao', 'consultarProcesso'))->toHaveCount(1)
        ->and(chavesOrdenadasCamposDosAutos($item))->toBe(chavesEsperadasCamposDosAutos())
        ->and($item['tipo'])->toBe('Certidão')
        ->and($item['mimetype'])->toBe('application/pdf')
        ->and($item['usuario_juntada_arquivo'])->toBe('LUCAS MONTEIRO PEREIRA')
        ->and($item['data_juntada'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/')
        ->and($item['file_size'])->toBeNull()
        ->and($item['ocr_situacao'])->toBe('pendente');
});

it('mimetype vazio sai "" e file_size null sai null', function () {
    $processo = processoCamposDosAutos();
    documentoCamposDosAutos($processo, 9808002, [
        'mimetype' => '',
        'file_size' => null,
    ]);

    $response = visualizarCamposDosAutos($processo->tribunal_id)->assertOk();

    expect($response->json('documentos.0'))->toHaveKey('mimetype')
        ->and($response->json('documentos.0.mimetype'))->toBe('')
        ->and($response->json('documentos.0'))->toHaveKey('file_size')
        ->and($response->json('documentos.0.file_size'))->toBeNull()
        ->and($response->getContent())->toContain('"mimetype":""')
        ->and($response->getContent())->toContain('"file_size":null');
});

it('nenhuma das 14 chaves proibidas aparece em nenhum item, nos 2 caminhos', function () {
    $processo = processoCamposDosAutos();
    documentoCamposDosAutos($processo, 9808003, [
        'status' => ProcessoDocumento::STATUS_BAIXADO,
        'path' => 'documentos-processos/x/9808003.pdf',
        'path_html' => 'documentos-processos/x/9808003.html',
        'hash' => 'hash-9808003',
        'url' => 'https://s3.fake/x',
        'ocr_situacao' => ProcessoDocumento::OCR_ERRO,
        'ocr_job_id' => 'job-9808003',
        'ocr_job_id_cancelar' => 'job-antigo-9808003',
        'ocr_enviado_em' => now(),
        'ocr_concluido_em' => now(),
        'ocr_erro' => 'falhou no workflow',
    ]);
    DB::table('processo_documentos')
        ->where('processo_id', $processo->id)
        ->update(['conteudo_html' => '<p>legado</p>']);
    documentoCamposDosAutos($processo, 9808004, ['mimetype' => 'video/mp4']);

    $noBanco = visualizarCamposDosAutos($processo->tribunal_id)->assertOk()->json('documentos');

    $tribunalFora = Tribunal::factory()->create();
    mniForaDoBancoCamposDosAutos(9808102);
    $foraDoBanco = visualizarCamposDosAutos($tribunalFora->id)->assertOk()->json('documentos');

    $itens = array_merge($noBanco, $foraDoBanco);

    expect($itens)->toHaveCount(3);

    foreach ($itens as $item) {
        expect(array_intersect(CHAVES_PROIBIDAS_CAMPOS_DOS_AUTOS, array_keys($item)))->toBe([])
            ->and(chavesOrdenadasCamposDosAutos($item))->toBe(chavesEsperadasCamposDosAutos());
    }
});

// ---------- RF-02: tipo ----------

it('tipo vem da linha do processo, e não do mesmo código em outra linha', function () {
    $processo = processoCamposDosAutos();
    $outraLinha = Tribunal::factory()->create();
    tipoCamposDosAutos($outraLinha->id, '57', 'Outro');
    tipoCamposDosAutos($processo->tribunal_id, '57', 'Certidão');
    documentoCamposDosAutos($processo, 9808011, ['tipo_documento' => '57']);

    visualizarCamposDosAutos($processo->tribunal_id)
        ->assertOk()
        ->assertJsonPath('documentos.0.tipo', 'Certidão');
});

it('tipo sai null sem registro com o código do documento na linha do processo', function () {
    $processo = processoCamposDosAutos();
    $outraLinha = Tribunal::factory()->create();
    tipoCamposDosAutos($outraLinha->id, '58', 'Só na outra linha');
    documentoCamposDosAutos($processo, 9808012, ['tipo_documento' => '58']);

    $response = visualizarCamposDosAutos($processo->tribunal_id)->assertOk();

    expect($response->json('documentos.0'))->toHaveKey('tipo')
        ->and($response->json('documentos.0.tipo'))->toBeNull();
});

it('com o mesmo código repetido na linha, vale o tipo de menor id', function () {
    $processo = processoCamposDosAutos();
    $menor = tipoCamposDosAutos($processo->tribunal_id, '57', 'Certidão');
    $maior = tipoCamposDosAutos($processo->tribunal_id, '57', 'Certidão antiga');
    documentoCamposDosAutos($processo, 9808013, ['tipo_documento' => '57']);

    expect($menor->id)->toBeLessThan($maior->id);

    visualizarCamposDosAutos($processo->tribunal_id)
        ->assertOk()
        ->assertJsonPath('documentos.0.tipo', 'Certidão');
});

it('tipo excluído não conta', function () {
    $processo = processoCamposDosAutos();
    // o excluído tem o menor id: se contasse, ganharia
    tipoCamposDosAutos($processo->tribunal_id, '57', 'Excluído')->delete();
    tipoCamposDosAutos($processo->tribunal_id, '57', 'Certidão');
    tipoCamposDosAutos($processo->tribunal_id, '60', 'Só excluído')->delete();
    documentoCamposDosAutos($processo, 9808014, ['tipo_documento' => '57']);
    documentoCamposDosAutos($processo, 9808015, ['tipo_documento' => '60']);

    $documentos = documentosPorIdCamposDosAutos(visualizarCamposDosAutos($processo->tribunal_id)->assertOk());

    expect($documentos[9808014]['tipo'])->toBe('Certidão')
        ->and($documentos[9808015]['tipo'])->toBeNull();
});

// ---------- RNF-02: consultas que não dependem do número de documentos ----------

/**
 * As consultas de uma chamada a /processo/visualizar. O token fica com
 * `last_used_at` = agora, para tirar a escrita de `registrarUso`.
 *
 * @return array<int, string>
 */
function consultasDaChamadaCamposDosAutos(Processo $processo): array
{
    ApiToken::query()
        ->where('token', ApiToken::hashToken('tk-test'))
        ->update(['last_used_at' => now()]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    visualizarCamposDosAutos($processo->tribunal_id, $processo->numero_processo)->assertOk();

    $consultas = collect(DB::getQueryLog())->pluck('query')->all();
    DB::disableQueryLog();
    DB::flushQueryLog();

    return $consultas;
}

it('1 e 50 documentos fazem o mesmo número de consultas, com 1 consulta a tipos_documentos em cada', function () {
    $umDocumento = processoCamposDosAutos(numero: '99998081220268030011');
    tipoCamposDosAutos($umDocumento->tribunal_id, '1001', 'Tipo 1001');
    documentoCamposDosAutos($umDocumento, 9808200, ['tipo_documento' => '1001']);

    $cinquenta = processoCamposDosAutos(numero: '99998081220268030012');
    for ($i = 0; $i < 50; $i++) {
        $codigo = (string) (2000 + $i);
        tipoCamposDosAutos($cinquenta->tribunal_id, $codigo, "Tipo {$codigo}");
        documentoCamposDosAutos($cinquenta, 9808300 + $i, ['tipo_documento' => $codigo]);
    }

    $consultasDeUm = consultasDaChamadaCamposDosAutos($umDocumento);
    $consultasDeCinquenta = consultasDaChamadaCamposDosAutos($cinquenta);

    $aTipos = fn (array $consultas) => collect($consultas)
        ->filter(fn (string $sql) => str_contains($sql, 'tipos_documentos'))
        ->count();

    expect(count($consultasDeCinquenta))->toBe(count($consultasDeUm))
        ->and($aTipos($consultasDeUm))->toBe(1)
        ->and($aTipos($consultasDeCinquenta))->toBe(1);

    $documentos = documentosPorIdCamposDosAutos(visualizarCamposDosAutos($cinquenta->tribunal_id, $cinquenta->numero_processo));
    expect($documentos)->toHaveCount(50)
        ->and($documentos[9808300]['tipo'])->toBe('Tipo 2000')
        ->and($documentos[9808349]['tipo'])->toBe('Tipo 2049');
});

// ---------- RF-03: situação calculada ----------

dataset('situacao_calculada_campos_dos_autos', [
    'video/mp4 / null' => ['video/mp4', null, 'ignorado'],
    'application/pdf / null' => ['application/pdf', null, 'pendente'],
    "'' / null" => ['', null, 'pendente'],
    'application/pdf / processado' => ['application/pdf', 'processado', 'processado'],
    'text/html / enviado' => ['text/html', 'enviado', 'enviado'],
    'image/png / erro' => ['image/png', 'erro', 'erro'],
    "'' / erro" => ['', 'erro', 'erro'],
    'audio/mpeg / null' => ['audio/mpeg', null, 'ignorado'],
    'application/zip / pendente' => ['application/zip', 'pendente', 'ignorado'],
    'video/mp4 / processado' => ['video/mp4', 'processado', 'ignorado'],
]);

it('ocr_situacao é calculada pela tabela de RF-03', function (string $mimetype, ?string $coluna, string $esperado) {
    $processo = processoCamposDosAutos();
    documentoCamposDosAutos($processo, 9808400, [
        'mimetype' => $mimetype,
        'ocr_situacao' => $coluna,
    ]);

    visualizarCamposDosAutos($processo->tribunal_id)
        ->assertOk()
        ->assertJsonPath('documentos.0.ocr_situacao', $esperado);
})->with('situacao_calculada_campos_dos_autos');

it('o dataset de RF-03 sai pela tabela num processo só, e a coluna ocr_situacao não muda no banco', function () {
    $processo = processoCamposDosAutos();
    $linhas = [
        ['video/mp4', null, 'ignorado'],
        ['application/pdf', null, 'pendente'],
        ['', null, 'pendente'],
        ['application/pdf', 'processado', 'processado'],
        ['text/html', 'enviado', 'enviado'],
        ['image/png', 'erro', 'erro'],
        ['', 'erro', 'erro'],
        ['audio/mpeg', null, 'ignorado'],
        ['application/zip', 'pendente', 'ignorado'],
        ['video/mp4', 'processado', 'ignorado'],
    ];

    foreach ($linhas as $i => [$mimetype, $coluna]) {
        documentoCamposDosAutos($processo, 9808500 + $i, [
            'mimetype' => $mimetype,
            'ocr_situacao' => $coluna,
            'data_hora' => sprintf('2026-03-06 10:%02d:00', $i),
        ]);
    }

    $colunasAntes = DB::table('processo_documentos')
        ->where('processo_id', $processo->id)
        ->orderBy('id_documento')
        ->pluck('ocr_situacao', 'id_documento')
        ->all();

    $response = visualizarCamposDosAutos($processo->tribunal_id)->assertOk();
    $documentos = documentosPorIdCamposDosAutos($response);

    foreach ($linhas as $i => [, , $esperado]) {
        expect($documentos[9808500 + $i]['ocr_situacao'])->toBe($esperado);
    }

    // nunca null, sempre um dos 5 valores
    expect(collect($documentos)->pluck('ocr_situacao')->unique()->sort()->values()->all())
        ->toBe(['enviado', 'erro', 'ignorado', 'pendente', 'processado']);

    // a ordem dos documentos não muda: data_hora decrescente
    expect(collect($response->json('documentos'))->pluck('id_documento')->map(fn ($id) => (int) $id)->all())
        ->toBe(array_reverse(range(9808500, 9808509)));

    $colunasDepois = DB::table('processo_documentos')
        ->where('processo_id', $processo->id)
        ->orderBy('id_documento')
        ->pluck('ocr_situacao', 'id_documento')
        ->all();

    expect($colunasDepois)->toBe($colunasAntes)
        ->and(collect($colunasDepois)->filter(fn ($valor) => $valor === null))->toHaveCount(4);

    // a mesma regra da contagem (RF-05, base): ignorado = ignorados, e o
    // resto é o total
    $contagem = app(ContagemOcrService::class)->contar($processo->id);
    $situacoes = collect($documentos)->pluck('ocr_situacao');

    expect($situacoes->filter(fn ($s) => $s === 'ignorado')->count())->toBe($contagem->ignorados)
        ->and($situacoes->reject(fn ($s) => $s === 'ignorado')->count())->toBe($contagem->total)
        ->and($situacoes->filter(fn ($s) => $s === 'processado')->count())->toBe($contagem->processados)
        ->and($situacoes->filter(fn ($s) => $s === 'erro')->count())->toBe($contagem->comErro);
});
