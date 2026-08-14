<?php

use App\Services\MNI\PadronizarMensagemMniService;

function mensagemLoginPadronizada(): string
{
    return 'Erro ao realizar login via MNI. Verifique suas credenciais.';
}

it('traduz o retorno cru de login inválido do PJe', function () {
    $crua = 'Erro ao realizar login via MNI. exception invoking: loginFailed';

    expect(PadronizarMensagemMniService::normalizar($crua))->toBe(mensagemLoginPadronizada());
});

it('reconhece a falha de login mesmo com ruído em volta', function (string $crua) {
    expect(PadronizarMensagemMniService::normalizar($crua))->toBe(mensagemLoginPadronizada());
})->with([
    'só o trecho do java' => 'exception invoking: loginFailed',
    'só o prefixo do MNI' => 'Erro ao realizar login via MNI.',
    'caixa trocada' => 'ERRO AO REALIZAR LOGIN VIA MNI. EXCEPTION INVOKING: LOGINFAILED',
    'com stack trace' => "org.apache.cxf.interceptor.Fault: exception invoking: loginFailed\n\tat br.jus.pje...",
    'com espaços nas pontas' => '  Erro ao realizar login via MNI. exception invoking: loginFailed  ',
]);

it('é idempotente: normalizar a mensagem já padronizada devolve ela mesma', function () {
    $umaVez = PadronizarMensagemMniService::normalizar('exception invoking: loginFailed');

    expect(PadronizarMensagemMniService::normalizar($umaVez))->toBe($umaVez)
        ->and($umaVez)->toBe(mensagemLoginPadronizada());
});

it('deixa passar intacta a mensagem que não tem regra', function (string $crua) {
    expect(PadronizarMensagemMniService::normalizar($crua))->toBe(trim($crua));
})->with([
    'MNI indisponível',
    'Documento não encontrado neste processo',
    'Processo não encontrado ou sem permissão de acesso',
]);

it('mensagem vazia ou nula vira string vazia', function (?string $crua) {
    expect(PadronizarMensagemMniService::normalizar($crua))->toBe('');
})->with([null, '', '   ']);
