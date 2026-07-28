<?php

it('expoe as chaves de credenciais padrao do PJe', function () {
    expect(config('pje.credenciais_padrao'))->toBeArray()
        ->toHaveKeys(['login', 'senha']);
});

it('mapeia as credenciais padrao para as variaveis de ambiente corretas', function () {
    expect(config('pje.credenciais_padrao.login'))->toBe(env('PJE_LOGIN_PADRAO'))
        ->and(config('pje.credenciais_padrao.senha'))->toBe(env('PJE_SENHA_PADRAO'));
});
