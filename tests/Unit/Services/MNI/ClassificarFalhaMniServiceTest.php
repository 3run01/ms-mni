<?php

use App\Exceptions\CredencialPjeRecusadaException;
use App\Exceptions\MNIException;
use App\Exceptions\ProcessoNaoEncontradoNoTribunalException;
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

// recusaNaCadeia (T17): o job de download do OCR reconhece a recusa pelo
// tipo, em qualquer elo da cadeia de getPrevious(), sem reler texto.

it('reconhece a recusa do par na própria exceção e na cadeia de previous', function (Closure $montar) {
    expect(ClassificarFalhaMniService::recusaNaCadeia($montar()))->toBeTrue();
})->with([
    'a própria exceção' => [fn () => new CredencialPjeRecusadaException('Erro ao realizar login via MNI. exception invoking: loginFailed', 500)],
    'embrulhada pelo download do PDF' => [fn () => new Exception(
        'Erro ao processar documento PDF: Erro ao realizar login via MNI.',
        0,
        new CredencialPjeRecusadaException('Erro ao realizar login via MNI. exception invoking: loginFailed', 500),
    )],
    'dois elos abaixo' => [fn () => new RuntimeException('externo', 0, new Exception(
        'Erro ao processar documento HTML: falha',
        0,
        new CredencialPjeRecusadaException('loginFailed', 500),
    ))],
]);

it('não reconhece a recusa sem CredencialPjeRecusadaException na cadeia', function (Closure $montar) {
    expect(ClassificarFalhaMniService::recusaNaCadeia($montar()))->toBeFalse();
})->with([
    'MNIException comum' => [fn () => new MNIException('Visualização do documento bloqueada', 500)],
    'processo não encontrado' => [fn () => new ProcessoNaoEncontradoNoTribunalException('Processo de número 1 não encontrado!', 500)],
    // Puro pelo tipo: o texto sozinho não basta (a classificação do texto
    // é das operações MNI, que só a fazem com o par informado).
    'texto com loginfailed sem o tipo' => [fn () => new Exception('exception invoking: loginFailed')],
    'wrapper sem previous' => [fn () => new Exception('Erro ao processar documento PDF: timeout')],
    'cadeia sem a recusa' => [fn () => new Exception('Erro ao processar documento PDF: x', 0, new MNIException('timeout', 500))],
]);
