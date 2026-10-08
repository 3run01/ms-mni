<?php

use App\Exceptions\ConsultaMNISemSucessoException;
use App\Exceptions\CredencialPjeRecusadaException;
use App\Exceptions\MNIException;
use App\Exceptions\ProcessoNaoEncontradoNoTribunalException;

it('as exceções classificadas são MNIException e não ConsultaMNISemSucessoException', function (string $classe) {
    $e = new $classe('qualquer', 500);

    expect($e)->toBeInstanceOf(MNIException::class)
        ->and($e)->not->toBeInstanceOf(ConsultaMNISemSucessoException::class)
        ->and(get_parent_class($e))->toBe(MNIException::class);
})->with([
    CredencialPjeRecusadaException::class,
    ProcessoNaoEncontradoNoTribunalException::class,
]);

it('CredencialPjeRecusadaException padroniza o texto cru de login em getError e mantém o code 500', function () {
    $e = new CredencialPjeRecusadaException('Erro ao realizar login via MNI. exception invoking: loginFailed', 500);

    expect($e->getError())->toBe('Erro ao realizar login via MNI. Verifique suas credenciais.')
        ->and($e->getCode())->toBe(500);
});

it('ProcessoNaoEncontradoNoTribunalException mantém o texto cru do tribunal em getError', function () {
    $crua = 'Processo de número 99999901120268030001 não encontrado!';
    $e = new ProcessoNaoEncontradoNoTribunalException($crua, 500);

    expect($e->getError())->toBe($crua)
        ->and($e->getCode())->toBe(500);
});

// O status HTTP é decidido em app/Http; as exceções só carregam o sinal.
it('as exceções classificadas não declaram métodos nem tratam HTTP', function (string $classe) {
    $reflexao = new ReflectionClass($classe);
    $proprios = array_filter(
        $reflexao->getMethods(),
        fn (ReflectionMethod $m) => $m->getDeclaringClass()->getName() === $classe
    );
    $fonte = file_get_contents($reflexao->getFileName());

    expect($proprios)->toBeEmpty()
        ->and($fonte)->not->toContain('response(')
        ->and($fonte)->not->toContain('JsonResponse');
})->with([
    CredencialPjeRecusadaException::class,
    ProcessoNaoEncontradoNoTribunalException::class,
]);
