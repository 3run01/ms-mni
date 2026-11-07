<?php

use Illuminate\Routing\Route as RotaLaravel;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

/*
 * Contrato oficial × cópia acordada com o Sistema Jurídico (DEP-03 e DEP-05;
 * RNF-04, CT-01, CT-02).
 *
 * Os nós `/processo/ocr`, o webhook `ocr_progresso` e os schemas
 * `SolicitacaoDeOcr`, `ProcessamentoDeOcr` e `AvisoDeProgressoDoOcr` são os
 * da cópia acordada (`sistema-juridico-lorran/tests/Fixtures/Mni/openapi.yaml`,
 * l. 423-499, 771-799 e 835-909). A estrutura esperada abaixo é a dessa
 * cópia sem `description`, `example`, `examples` e `summary` (texto livre),
 * como em OpenApiContratoDep01Test. A única diferença estrutural é
 * `maxLength: 2048` em `callback_url` (D-05). As demais divergências
 * (D-01 a D-08) ficam no texto livre e na lista do `info`.
 */

const CHAVES_TEXTO_LIVRE_CONTRATO_OCR = ['description', 'example', 'examples', 'summary'];

function contratoOficialOcr(): array
{
    return Yaml::parseFile(base_path('docs/api/openapi.yaml'));
}

function semTextoLivreContratoOcr(array $no): array
{
    $resultado = [];

    foreach ($no as $chave => $valor) {
        if (in_array($chave, CHAVES_TEXTO_LIVRE_CONTRATO_OCR, true)) {
            continue;
        }

        $resultado[$chave] = is_array($valor) ? semTextoLivreContratoOcr($valor) : $valor;
    }

    return $resultado;
}

function noDoContratoOcr(array $caminho): mixed
{
    $no = contratoOficialOcr();

    foreach ($caminho as $chave) {
        expect($no)->toHaveKey($chave);
        $no = $no[$chave];
    }

    return $no;
}

function respostaJsonContratoOcr(array $schema): array
{
    return ['content' => ['application/json' => ['schema' => $schema]]];
}

function respostaComCodigoContratoOcr(string $codigo): array
{
    return respostaJsonContratoOcr([
        'allOf' => [
            ['$ref' => '#/components/schemas/ErroComCodigo'],
            ['properties' => ['codigo' => ['const' => $codigo]]],
        ],
    ]);
}

/**
 * Todos os `$ref` de um nó, em qualquer profundidade.
 *
 * @return list<string>
 */
function refsDoNoContratoOcr(array $no): array
{
    $refs = [];

    array_walk_recursive($no, function ($valor, $chave) use (&$refs) {
        if ($chave === '$ref') {
            $refs[] = $valor;
        }
    });

    return array_values(array_unique($refs));
}

it('tem os nós DEP-03 e DEP-05 iguais à cópia acordada, ignorando o texto livre, com maxLength 2048 em callback_url (D-05)', function (array $caminho, array $esperado) {
    expect(semTextoLivreContratoOcr(noDoContratoOcr($caminho)))->toEqual($esperado);
})->with([
    'POST /processo/ocr' => [
        ['paths', '/processo/ocr', 'post'],
        [
            'tags' => ['Documentos'],
            'x-dependencia' => 'DEP-03',
            'security' => [['ApiToken' => []]],
            'requestBody' => [
                'required' => true,
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SolicitacaoDeOcr']]],
            ],
            'responses' => [
                '202' => respostaJsonContratoOcr(['$ref' => '#/components/schemas/ProcessamentoDeOcr']),
                '401' => ['$ref' => '#/components/responses/Erro401'],
                '403' => respostaComCodigoContratoOcr('credencial_pje_recusada'),
                '404' => respostaComCodigoContratoOcr('processo_nao_encontrado'),
                '422' => respostaJsonContratoOcr(['$ref' => '#/components/schemas/ErroMensagem']),
                '500' => ['$ref' => '#/components/responses/Erro500Generico'],
            ],
        ],
    ],
    'webhook ocr_progresso' => [
        ['webhooks', 'ocr_progresso', 'post'],
        [
            'x-dependencia' => 'DEP-05',
            'requestBody' => [
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/AvisoDeProgressoDoOcr']]],
            ],
            'responses' => [
                '200' => [],
                '4XX' => [],
            ],
        ],
    ],
    'components.schemas.SolicitacaoDeOcr' => [
        ['components', 'schemas', 'SolicitacaoDeOcr'],
        [
            'type' => 'object',
            'x-dependencia' => 'DEP-03',
            'required' => ['numero_processo', 'tribunal_id', 'login_pje', 'senha_pje', 'callback_url', 'callback_token'],
            'properties' => [
                'numero_processo' => ['type' => 'string', 'pattern' => '^[0-9]{20}$'],
                'tribunal_id' => ['type' => 'integer', 'minimum' => 1],
                'login_pje' => ['type' => 'string', 'minLength' => 1],
                'senha_pje' => ['type' => 'string', 'minLength' => 1],
                // D-05: a cópia acordada não tem maxLength.
                'callback_url' => ['type' => 'string', 'format' => 'uri', 'maxLength' => 2048],
                'callback_token' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
            ],
        ],
    ],
    'components.schemas.ProcessamentoDeOcr' => [
        ['components', 'schemas', 'ProcessamentoDeOcr'],
        [
            'type' => 'object',
            'x-dependencia' => 'DEP-03',
            'required' => ['numero_processo', 'tribunal_id', 'total', 'processados', 'com_erro', 'ignorados', 'situacao'],
            'properties' => [
                'numero_processo' => ['type' => 'string', 'pattern' => '^[0-9]{20}$'],
                'tribunal_id' => ['type' => 'integer', 'minimum' => 1],
                'total' => ['type' => 'integer', 'minimum' => 0],
                'processados' => ['type' => 'integer', 'minimum' => 0],
                'com_erro' => ['type' => 'integer', 'minimum' => 0],
                'ignorados' => ['type' => 'integer', 'minimum' => 0],
                'situacao' => ['type' => 'string', 'enum' => ['em_andamento', 'concluido']],
            ],
        ],
    ],
    'components.schemas.AvisoDeProgressoDoOcr' => [
        ['components', 'schemas', 'AvisoDeProgressoDoOcr'],
        [
            'type' => 'object',
            'x-dependencia' => 'DEP-05',
            'required' => ['numero_processo', 'tribunal_id', 'total', 'processados', 'com_erro', 'ignorados', 'situacao'],
            'properties' => [
                'numero_processo' => ['type' => 'string', 'pattern' => '^[0-9]{20}$'],
                'tribunal_id' => ['type' => 'integer', 'minimum' => 1],
                'total' => ['type' => 'integer', 'minimum' => 0],
                'processados' => ['type' => 'integer', 'minimum' => 0],
                'com_erro' => ['type' => 'integer', 'minimum' => 0],
                'ignorados' => ['type' => 'integer', 'minimum' => 0],
                'situacao' => ['type' => 'string', 'enum' => ['em_andamento', 'concluido', 'falhou']],
                'codigo' => ['type' => 'string', 'enum' => ['credencial_pje_recusada', 'credencial_de_outro_pedido_recusada']],
            ],
            'if' => ['required' => ['codigo']],
            'then' => ['properties' => ['situacao' => ['const' => 'falhou']]],
        ],
    ],
]);

it('resolve todo $ref dos nós do OCR no próprio contrato', function () {
    $contrato = contratoOficialOcr();

    $refs = refsDoNoContratoOcr([
        $contrato['paths']['/processo/ocr'],
        $contrato['webhooks']['ocr_progresso'],
        $contrato['components']['schemas']['SolicitacaoDeOcr'],
        $contrato['components']['schemas']['ProcessamentoDeOcr'],
        $contrato['components']['schemas']['AvisoDeProgressoDoOcr'],
    ]);

    expect($refs)->not->toBeEmpty();

    foreach ($refs as $ref) {
        expect($ref)->toStartWith('#/');

        $no = $contrato;
        foreach (explode('/', substr($ref, 2)) as $chave) {
            expect($no)->toHaveKey($chave);
            $no = $no[$chave];
        }
    }
});

it('mantém as respostas comuns usadas pelo /processo/ocr iguais às da cópia acordada', function (array $caminho, array $esperado) {
    expect(semTextoLivreContratoOcr(noDoContratoOcr($caminho)))->toEqual($esperado);
})->with([
    'responses.Erro401' => [
        ['components', 'responses', 'Erro401'],
        respostaJsonContratoOcr(['$ref' => '#/components/schemas/ErroMensagem']),
    ],
    'responses.Erro500Generico' => [
        ['components', 'responses', 'Erro500Generico'],
        respostaJsonContratoOcr(['$ref' => '#/components/schemas/ErroMensagem']),
    ],
    'schemas.ErroMensagem' => [
        ['components', 'schemas', 'ErroMensagem'],
        ['type' => 'object', 'properties' => ['message' => ['type' => 'string']], 'required' => ['message']],
    ],
]);

it('lista as divergências D-01 a D-08 no info do contrato', function () {
    $descricao = contratoOficialOcr()['info']['description'];

    expect($descricao)->toContain('Mudanças acordadas (DEP-03 e DEP-05)');

    foreach (range(1, 8) as $n) {
        expect($descricao)->toContain(sprintf('**D-%02d**', $n));
    }
});

it('documenta o /ocr/webhook, que só existe no contrato do ms-mni (D-01)', function () {
    expect(contratoOficialOcr()['paths'])->toHaveKey('/ocr/webhook')
        ->and(contratoOficialOcr()['paths']['/ocr/webhook'])->toHaveKey('post');
});

it('documenta a rota registrada POST api/processo/ocr', function () {
    $rota = collect(Route::getRoutes()->getRoutes())
        ->first(fn (RotaLaravel $rota) => $rota->uri() === 'api/processo/ocr' && in_array('POST', $rota->methods(), true));

    expect($rota)->not->toBeNull()
        ->and(contratoOficialOcr()['paths']['/processo/ocr'])->toHaveKey('post');
});
