<?php

use App\Exceptions\ConsultaMNISemSucessoException;
use App\Exceptions\MNIException;

it('estende MNIException', function () {
    expect(new ConsultaMNISemSucessoException('qualquer', 500))->toBeInstanceOf(MNIException::class);
});

it('padroniza a mensagem do tribunal em getError', function () {
    $e = new ConsultaMNISemSucessoException('loginFailed', 500);

    expect($e->getError())->toBe('Erro ao realizar login via MNI. Verifique suas credenciais.')
        ->and($e->getCode())->toBe(500);
});

it('mantém intacta a mensagem sem regra de padronização', function () {
    $e = new ConsultaMNISemSucessoException('Processo não encontrado', 500);

    expect($e->getError())->toBe('Processo não encontrado');
});

// O download em lote separa o fallback (sucesso = false) da falha total
// (transporte, SOAP fault, timeout) só pelo tipo da exceção.
it('se distingue por instanceof das demais falhas MNI', function () {
    $transporte = new MNIException('Could not connect to host', 500);
    $soapFault = new SoapFault('Server', 'Internal Error');

    expect($transporte)->not->toBeInstanceOf(ConsultaMNISemSucessoException::class)
        ->and($soapFault)->not->toBeInstanceOf(ConsultaMNISemSucessoException::class)
        ->and(new ConsultaMNISemSucessoException('sem sucesso', 500))->toBeInstanceOf(ConsultaMNISemSucessoException::class);
});

it('pode ser capturada como MNIException por quem já trata o erro MNI', function () {
    $capturada = null;

    try {
        throw new ConsultaMNISemSucessoException('loginFailed', 500);
    } catch (MNIException $e) {
        $capturada = $e;
    }

    expect($capturada)->toBeInstanceOf(ConsultaMNISemSucessoException::class)
        ->and($capturada->getError())->toBe('Erro ao realizar login via MNI. Verifique suas credenciais.');
});
