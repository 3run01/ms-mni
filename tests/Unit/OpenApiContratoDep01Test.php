<?php

use Symfony\Component\Yaml\Yaml;

/*
 * Contrato oficial × cópia acordada com o Sistema Jurídico (DEP-01).
 *
 * Os 5 nós DEP-01 foram copiados literalmente da cópia acordada. A estrutura
 * esperada abaixo é a dessa cópia sem `description`, `example`, `examples` e
 * `summary` (texto livre, Q-09), mantendo `x-dependencia`. Qualquer mudança de
 * nome, status, campo ou estrutura nesses nós quebra o cliente.
 */

const CHAVES_TEXTO_LIVRE_CONTRATO_DEP01 = ['description', 'example', 'examples', 'summary'];

function contratoOficialDep01(): array
{
    return Yaml::parseFile(base_path('docs/api/openapi.yaml'));
}

function semTextoLivreContratoDep01(array $no): array
{
    $resultado = [];

    foreach ($no as $chave => $valor) {
        if (in_array($chave, CHAVES_TEXTO_LIVRE_CONTRATO_DEP01, true)) {
            continue;
        }

        $resultado[$chave] = is_array($valor) ? semTextoLivreContratoDep01($valor) : $valor;
    }

    return $resultado;
}

function respostaComCodigoContratoDep01(string $codigo): array
{
    return [
        'x-dependencia' => 'DEP-01',
        'content' => [
            'application/json' => [
                'schema' => [
                    'allOf' => [
                        ['$ref' => '#/components/schemas/ErroComCodigo'],
                        ['properties' => ['codigo' => ['const' => $codigo]]],
                    ],
                ],
            ],
        ],
    ];
}

it('carrega o contrato oficial como YAML OpenAPI 3.1.0', function () {
    expect(contratoOficialDep01()['openapi'])->toBe('3.1.0');
});

it('tem os nós DEP-01 iguais à cópia acordada, ignorando o texto livre', function (array $caminho, array $esperado) {
    $no = contratoOficialDep01();

    foreach ($caminho as $chave) {
        expect($no)->toHaveKey($chave);
        $no = $no[$chave];
    }

    expect(semTextoLivreContratoDep01($no))->toEqual($esperado);
})->with([
    '/processo/visualizar 403' => [
        ['paths', '/processo/visualizar', 'get', 'responses', '403'],
        respostaComCodigoContratoDep01('credencial_pje_recusada'),
    ],
    '/processo/visualizar 404' => [
        ['paths', '/processo/visualizar', 'get', 'responses', '404'],
        respostaComCodigoContratoDep01('processo_nao_encontrado'),
    ],
    '/documento/visualizar 403' => [
        ['paths', '/documento/visualizar', 'get', 'responses', '403'],
        respostaComCodigoContratoDep01('credencial_pje_recusada'),
    ],
    '/documento/visualizar 404' => [
        ['paths', '/documento/visualizar', 'get', 'responses', '404'],
        [
            'x-dependencia' => 'DEP-01',
            'content' => [
                'application/json' => [
                    'schema' => [
                        'oneOf' => [
                            [
                                'allOf' => [
                                    ['$ref' => '#/components/schemas/ErroComCodigo'],
                                    ['properties' => ['codigo' => ['const' => 'processo_nao_encontrado']]],
                                ],
                            ],
                            [
                                'allOf' => [
                                    ['$ref' => '#/components/schemas/ErroMensagem'],
                                    ['not' => ['required' => ['codigo']]],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'components.schemas.ErroComCodigo' => [
        ['components', 'schemas', 'ErroComCodigo'],
        [
            'type' => 'object',
            'x-dependencia' => 'DEP-01',
            'properties' => [
                'codigo' => ['type' => 'string', 'enum' => ['credencial_pje_recusada', 'processo_nao_encontrado']],
                'message' => ['type' => 'string'],
            ],
            'required' => ['codigo', 'message'],
        ],
    ],
]);

it('não traz sigla nem grau no schema Tribunal (DEP-02 fora do escopo)', function () {
    expect(contratoOficialDep01()['components']['schemas']['Tribunal']['properties'])
        ->not->toHaveKey('sigla')
        ->not->toHaveKey('grau');
});

it('mantém em PaginacaoProcessos os campos que só existem no contrato oficial', function () {
    expect(contratoOficialDep01()['components']['schemas']['PaginacaoProcessos']['properties'])
        ->toHaveKeys(['first_page_url', 'from', 'last_page_url', 'next_page_url', 'path', 'prev_page_url', 'to']);
});
