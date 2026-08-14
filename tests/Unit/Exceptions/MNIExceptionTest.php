<?php

use App\Exceptions\MNIException;

it('padroniza a mensagem crua do PJe já no construtor', function () {
    $e = new MNIException('Erro ao realizar login via MNI. exception invoking: loginFailed', 500);

    expect($e->getError())->toBe('Erro ao realizar login via MNI. Verifique suas credenciais.');
});

it('mantém intacta a mensagem que não tem regra de padronização', function () {
    $e = new MNIException('Documento não encontrado neste processo', 404);

    expect($e->getError())->toBe('Documento não encontrado neste processo');
});

it('não altera o code', function () {
    expect((new MNIException('qualquer', 422))->getCode())->toBe(422);
});

// Vários serviços fazem `throw new MNIException($e->getError(), 500)` ao
// repropagar; a mensagem não pode se degradar a cada salto.
it('sobrevive ao reempacotamento sem mudar a mensagem', function () {
    $original = new MNIException('exception invoking: loginFailed', 500);
    $repropagada = new MNIException($original->getError(), 500);

    expect($repropagada->getError())->toBe($original->getError());
});
