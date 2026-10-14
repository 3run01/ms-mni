<?php

use App\Services\IntegracaoBase;

function wsdlFixtureIntegracaoBase(string $nome): string
{
    return base_path("tests/Fixtures/mni/{$nome}.wsdl");
}

it('reconhece consultarProcessos no WSDL que declara a operacao', function () {
    $integracao = new IntegracaoBase(wsdlFixtureIntegracaoBase('intercomunicacao-com-consultarProcessos'));

    expect($integracao->possuiOperacao('consultarProcessos'))->toBeTrue();
});

it('nao encontra consultarProcessos no WSDL que so tem consultarProcesso', function () {
    $integracao = new IntegracaoBase(wsdlFixtureIntegracaoBase('intercomunicacao-sem-consultarProcessos'));

    expect($integracao->possuiOperacao('consultarProcessos'))->toBeFalse();
});

it('casa consultarProcesso exato nos dois WSDLs, sem confundir com consultarProcessos', function () {
    $com = new IntegracaoBase(wsdlFixtureIntegracaoBase('intercomunicacao-com-consultarProcessos'));
    $sem = new IntegracaoBase(wsdlFixtureIntegracaoBase('intercomunicacao-sem-consultarProcessos'));

    expect($com->possuiOperacao('consultarProcesso'))->toBeTrue()
        ->and($sem->possuiOperacao('consultarProcesso'))->toBeTrue();
});

it('nao casa parte do nome nem o tipo de retorno da operacao', function (string $nome) {
    $integracao = new IntegracaoBase(wsdlFixtureIntegracaoBase('intercomunicacao-com-consultarProcessos'));

    expect($integracao->possuiOperacao($nome))->toBeFalse();
})->with([
    'sufixo' => ['Processos'],
    'prefixo' => ['consultar'],
    'tipo de retorno' => ['consultarProcessosResposta'],
    'caixa diferente' => ['ConsultarProcessos'],
    'caractere de regex' => ['consultarProcesso.'],
]);

it('propaga a falha ao carregar o WSDL', function () {
    $integracao = new IntegracaoBase(base_path('tests/Fixtures/mni/nao-existe.wsdl'));

    expect(fn () => $integracao->possuiOperacao('consultarProcessos'))->toThrow(SoapFault::class);
});
