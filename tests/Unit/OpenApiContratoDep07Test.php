<?php

use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\TipoDocumento;
use App\Models\Tribunal;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Yaml\Yaml;

/*
 * Contrato oficial × cópia acordada com o Sistema Jurídico (DEP-07; T15:
 * CT-04, CT-01, CT-02, CT-03, CT-05 e RF-06 no contrato).
 *
 * O `docs/api/openapi.yaml` é cópia byte a byte da cópia acordada
 * (`sistema-juridico-lorran/tests/Fixtures/Mni/openapi.yaml`, SHA-256
 * 461fa57a…f464a), conferida por `cmp` fora da suíte: o teste não lê arquivo
 * fora do repositório. Aqui ficam os nós DEP-07 de que o Sistema depende
 * (as 13 chaves do `Documento`, a `ocr_situacao` com os 5 valores, o
 * `conteudo_html`, `Prioridade` e `RepresentanteProcessual`), a ausência de
 * texto de DEP-08 (CT-05) e a conferência de que a resposta real de
 * `GET /processo/visualizar` só usa chaves descritas em `Documento`.
 */

uses(DatabaseTransactions::class);

const CAMINHO_CONTRATO_DEP07 = 'docs/api/openapi.yaml';
const NUMERO_CONTRATO_DEP07 = '99998071520268030001';

/**
 * As 13 chaves de RF-01 e os tipos JSON que o contrato aceita para cada uma.
 * As 6 novas seguem a tabela de CT-01. `mimetype` e `ocr_situacao` aceitam
 * `null` no schema compartilhado com as outras rotas, mas a rota nunca manda
 * `null` (o provedor é mais estrito que o contrato, como registra o PLAN).
 */
const TIPOS_DOCUMENTO_CONTRATO_DEP07 = [
    'id_documento' => ['integer'],
    'descricao' => ['string', 'null'],
    'id_documento_vinculado' => ['integer', 'null'],
    'movimento' => ['string', 'null'],
    'tipo_documento' => ['string', 'null'],
    'data_hora' => ['string', 'null'],
    'nivel_sigilo' => ['integer', 'null'],
    'tipo' => ['string', 'null'],
    'data_juntada' => ['string', 'null'],
    'usuario_juntada_arquivo' => ['string', 'null'],
    'mimetype' => ['string', 'null'],
    'file_size' => ['integer', 'null'],
    'ocr_situacao' => ['string', 'null'],
];

const SITUACOES_OCR_CONTRATO_DEP07 = ['pendente', 'enviado', 'processado', 'erro', 'ignorado'];

function contratoOficialDep07(): array
{
    return Yaml::parseFile(base_path(CAMINHO_CONTRATO_DEP07));
}

function noDoContratoDep07(array $caminho): mixed
{
    $no = contratoOficialDep07();

    foreach ($caminho as $chave) {
        expect($no)->toHaveKey($chave);
        $no = $no[$chave];
    }

    return $no;
}

/**
 * Os tipos JSON aceitos por uma propriedade: o `type` direto ou os `type`
 * de cada opção do `oneOf`.
 *
 * @return list<string>
 */
function tiposDaPropriedadeContratoDep07(array $propriedade): array
{
    $opcoes = $propriedade['oneOf'] ?? [$propriedade];

    $tipos = [];

    foreach ($opcoes as $opcao) {
        foreach ((array) ($opcao['type'] ?? []) as $tipo) {
            $tipos[] = $tipo;
        }
    }

    sort($tipos);

    return $tipos;
}

/**
 * Todos os `$ref` do contrato, em qualquer profundidade.
 *
 * @return list<string>
 */
function refsDoContratoDep07(array $no): array
{
    $refs = [];

    array_walk_recursive($no, function ($valor, $chave) use (&$refs) {
        if ($chave === '$ref') {
            $refs[] = $valor;
        }
    });

    return array_values(array_unique($refs));
}

/**
 * Resolve um `$ref` local (`#/a/b/c`) no contrato. Devolve `null` quando o
 * caminho não existe.
 */
function resolverRefContratoDep07(array $contrato, string $ref): mixed
{
    if (!str_starts_with($ref, '#/')) {
        return null;
    }

    $no = $contrato;

    foreach (explode('/', substr($ref, 2)) as $parte) {
        $parte = str_replace(['~1', '~0'], ['/', '~'], $parte);

        if (!is_array($no) || !array_key_exists($parte, $no)) {
            return null;
        }

        $no = $no[$parte];
    }

    return $no;
}

it('carrega o contrato oficial como YAML OpenAPI 3.1.0', function () {
    expect(contratoOficialDep07()['openapi'])->toBe('3.1.0');
});

it('descreve em Documento as 13 chaves de RF-01 com os tipos de CT-01', function (string $chave, array $tipos) {
    $propriedades = noDoContratoDep07(['components', 'schemas', 'Documento', 'properties']);

    expect($propriedades)->toHaveKey($chave);

    $esperado = $tipos;
    sort($esperado);

    expect(tiposDaPropriedadeContratoDep07($propriedades[$chave]))->toBe($esperado);
})->with(fn () => collect(TIPOS_DOCUMENTO_CONTRATO_DEP07)
    ->map(fn (array $tipos, string $chave) => [$chave, $tipos])
    ->all());

it('descreve ocr_situacao com os 5 valores e a marca DEP-07', function () {
    $ocrSituacao = noDoContratoDep07(['components', 'schemas', 'Documento', 'properties', 'ocr_situacao']);

    expect($ocrSituacao['x-dependencia'] ?? null)->toBe('DEP-07');

    $comEnum = collect($ocrSituacao['oneOf'] ?? [])->first(fn (array $opcao) => isset($opcao['enum']));

    expect($comEnum)->not->toBeNull()
        ->and($comEnum['type'])->toBe('string')
        ->and($comEnum['enum'])->toBe(SITUACOES_OCR_CONTRATO_DEP07);
});

it('descreve conteudo_html em Documento com a marca DEP-07 (RF-06, CT-02)', function () {
    $conteudoHtml = noDoContratoDep07(['components', 'schemas', 'Documento', 'properties', 'conteudo_html']);

    expect($conteudoHtml['x-dependencia'] ?? null)->toBe('DEP-07')
        ->and(tiposDaPropriedadeContratoDep07($conteudoHtml))->toBe(['null', 'string']);
});

it('descreve Prioridade e RepresentanteProcessual e os referencia no Processo', function () {
    $schemas = noDoContratoDep07(['components', 'schemas']);

    expect($schemas)->toHaveKey('Prioridade')
        ->and($schemas)->toHaveKey('RepresentanteProcessual')
        ->and($schemas['Prioridade']['x-dependencia'] ?? null)->toBe('DEP-07')
        ->and($schemas['RepresentanteProcessual']['x-dependencia'] ?? null)->toBe('DEP-07')
        ->and($schemas['Processo']['properties']['prioridades']['items']['$ref'] ?? null)
        ->toBe('#/components/schemas/Prioridade')
        ->and($schemas['ProcessoParte']['properties']['representantes_processual']['items']['$ref'] ?? null)
        ->toBe('#/components/schemas/RepresentanteProcessual');
});

it('resolve todos os $ref do contrato', function () {
    $contrato = contratoOficialDep07();
    $refs = refsDoContratoDep07($contrato);

    expect($refs)->toContain('#/components/schemas/Prioridade')
        ->and($refs)->toContain('#/components/schemas/RepresentanteProcessual');

    $quebrados = array_values(array_filter(
        $refs,
        fn (string $ref) => !is_array(resolverRefContratoDep07($contrato, $ref)),
    ));

    expect($quebrados)->toBe([]);
});

it('não tem texto de DEP-08 (CT-05)', function () {
    expect(file_get_contents(base_path(CAMINHO_CONTRATO_DEP07)))->not->toContain('DEP-08');
});

it('só usa em GET /processo/visualizar chaves de documento descritas em Documento', function () {
    criarTokenApi();
    definirCredenciaisPadrao(null, null);
    Queue::fake();

    $tribunal = Tribunal::factory()->create();
    $processo = Processo::factory()->create([
        'tribunal_id' => $tribunal->id,
        'numero_processo' => NUMERO_CONTRATO_DEP07,
    ]);

    TipoDocumento::create([
        'tribunal_id' => $tribunal->id,
        'codigo' => '57',
        'descricao' => 'Certidão',
    ]);

    $base = [
        'processo_id' => $processo->id,
        'tipo_documento' => '57',
        'data_hora' => '2026-03-06 09:00:00',
        'status' => ProcessoDocumento::STATUS_PENDENTE,
        'tentativas_download' => 0,
    ];

    ProcessoDocumento::create(array_merge($base, [
        'id_documento' => 8807001,
        'descricao' => 'PDF processado',
        'mimetype' => 'application/pdf',
        'data_juntada' => '2026-03-06 10:00:00',
        'usuario_juntada_arquivo' => 'LUCAS MONTEIRO PEREIRA',
        'file_size' => 655360,
        'ocr_situacao' => ProcessoDocumento::OCR_PROCESSADO,
    ]));
    ProcessoDocumento::create(array_merge($base, [
        'id_documento' => 8807002,
        'descricao' => 'Sem mimetype',
        'mimetype' => '',
    ]));
    ProcessoDocumento::create(array_merge($base, [
        'id_documento' => 8807003,
        'descricao' => 'Vídeo',
        'mimetype' => 'video/mp4',
    ]));

    $resposta = $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/visualizar?' . http_build_query([
            'tribunal_id' => $tribunal->id,
            'numero_processo' => NUMERO_CONTRATO_DEP07,
            'login_pje' => 'login-contrato-dep07',
            'senha_pje' => 'senha-contrato-dep07',
        ]))
        ->assertOk();

    $documentos = $resposta->json('documentos');

    expect($documentos)->toHaveCount(3);

    $propriedades = array_keys(noDoContratoDep07(['components', 'schemas', 'Documento', 'properties']));

    foreach ($documentos as $documento) {
        expect(array_diff(array_keys($documento), $propriedades))->toBe([])
            ->and($documento['ocr_situacao'])->toBeIn(SITUACOES_OCR_CONTRATO_DEP07);
    }
});
