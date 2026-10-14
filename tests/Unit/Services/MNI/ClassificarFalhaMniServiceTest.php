<?php

use App\Services\MNI\ClassificarFalhaMniService;

it('reconhece a credencial recusada pelo loginfailed no texto cru', function (string $crua) {
    expect(ClassificarFalhaMniService::credencialRecusada($crua))->toBeTrue();
})->with([
    'sucesso false do PJe' => 'Erro ao realizar login via MNI. exception invoking: loginFailed',
    'fault CXF' => 'org.apache.cxf.interceptor.Fault: exception invoking: loginFailed',
    'caixa alta' => 'LOGINFAILED',
]);

// Só `loginfailed` classifica a recusa: o prefixo do MNI sozinho e o texto
// já padronizado não bastam (um falso positivo invalida a credencial do usuário).
it('não reconhece como credencial recusada o texto sem loginfailed', function (?string $crua) {
    expect(ClassificarFalhaMniService::credencialRecusada($crua))->toBeFalse();
})->with([
    'só o prefixo do MNI' => 'Erro ao realizar login via MNI.',
    'texto padronizado' => 'Erro ao realizar login via MNI. Verifique suas credenciais.',
    'vazio' => '',
    'nulo' => null,
]);

it('reconhece o processo não encontrado pelo texto capturado do tribunal', function (string $crua) {
    expect(ClassificarFalhaMniService::processoNaoEncontrado($crua))->toBeTrue();
})->with([
    'TJAP 1º grau' => 'Processo de número 99999901120268030001 não encontrado!',
    'TJAP 2º grau' => 'Processo de número 99999901420268030000 não encontrado!',
]);

it('não reconhece como processo não encontrado o texto fora do padrão capturado', function (?string $crua) {
    expect(ClassificarFalhaMniService::processoNaoEncontrado($crua))->toBeFalse();
})->with([
    'fixture genérica' => 'Processo não encontrado',
    'sem permissão de acesso' => 'Processo não encontrado ou sem permissão de acesso',
    'sem exclamação' => 'Processo de número 99999901120268030001 não encontrado',
    'com prefixo' => 'Erro: Processo de número 99999901120268030001 não encontrado!',
    'com espaço inicial' => ' Processo de número 1 não encontrado!',
    'número com máscara' => 'Processo de número 0600125-81.2024.8.03.0003 não encontrado!',
    'nulo' => null,
]);

it('expõe o regex exato do processo não encontrado', function () {
    expect(ClassificarFalhaMniService::PADRAO_PROCESSO_NAO_ENCONTRADO)
        ->toBe('/^Processo de número \d+ não encontrado!$/u');
});
