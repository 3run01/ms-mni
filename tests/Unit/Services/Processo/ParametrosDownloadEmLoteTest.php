<?php

use App\Services\Processo\ParametrosDownloadEmLote;

function definirParametrosLote(mixed $tamanhoLote, mixed $maxPaginas): void
{
    config()->set('pje.download_documentos', [
        'tamanho_lote' => $tamanhoLote,
        'max_paginas' => $maxPaginas,
    ]);
}

it('sem configuracao usa T = 20, tamanhoPagina = 20 e P = 2', function () {
    config()->set('pje.download_documentos', []);
    $parametros = new ParametrosDownloadEmLote();

    expect($parametros->tamanhoLote())->toBe(20)
        ->and($parametros->tamanhoPagina())->toBe(20)
        ->and($parametros->maxPaginas())->toBe(2);
});

it('com os padroes do config/pje.php usa T = 20 e P = 2', function () {
    definirParametrosLote(20, null);
    $parametros = new ParametrosDownloadEmLote();

    expect($parametros->tamanhoLote())->toBe(20)
        ->and($parametros->maxPaginas())->toBe(2);
});

it('usa o T configurado e o repete como tamanhoPagina', function () {
    definirParametrosLote(10, null);
    $parametros = new ParametrosDownloadEmLote();

    expect($parametros->tamanhoLote())->toBe(10)
        ->and($parametros->tamanhoPagina())->toBe(10);
});

it('aceita T vindo do env como string numerica', function () {
    definirParametrosLote('15', null);

    expect((new ParametrosDownloadEmLote())->tamanhoLote())->toBe(15);
});

it('volta T ao padrao 20 quando o valor e invalido', function (mixed $invalido) {
    definirParametrosLote($invalido, null);
    $parametros = new ParametrosDownloadEmLote();

    expect($parametros->tamanhoLote())->toBe(20)
        ->and($parametros->tamanhoPagina())->toBe(20);
})->with([
    'zero' => [0],
    'negativo' => [-5],
    'string negativa' => ['-1'],
    'nao numerico' => ['abc'],
    'vazio' => [''],
    'nulo' => [null],
]);

it('usa o P configurado', function () {
    definirParametrosLote(20, 3);

    expect((new ParametrosDownloadEmLote())->maxPaginas())->toBe(3);
});

it('aceita P vindo do env como string numerica', function () {
    definirParametrosLote(20, '4');

    expect((new ParametrosDownloadEmLote())->maxPaginas())->toBe(4);
});

it('volta P ao padrao da formula quando o valor e invalido', function (mixed $invalido) {
    definirParametrosLote(20, $invalido);

    expect((new ParametrosDownloadEmLote())->maxPaginas())->toBe(2);
})->with([
    'zero' => [0],
    'negativo' => [-1],
    'nao numerico' => ['abc'],
    'vazio' => [''],
]);

it('calcula P = ceil(T / tamanhoPagina) + 1 com o T configurado', function () {
    definirParametrosLote(7, null);
    $parametros = new ParametrosDownloadEmLote();

    expect($parametros->maxPaginas())
        ->toBe((int) ceil($parametros->tamanhoLote() / $parametros->tamanhoPagina()) + 1)
        ->toBe(2);
});

it('le a configuracao a cada chamada', function () {
    $parametros = new ParametrosDownloadEmLote();

    definirParametrosLote(10, null);
    expect($parametros->tamanhoLote())->toBe(10);

    definirParametrosLote(5, 6);
    expect($parametros->tamanhoLote())->toBe(5)
        ->and($parametros->maxPaginas())->toBe(6);
});
