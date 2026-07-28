<?php

it('expoe as chaves de credenciais padrao do PJe', function () {
    expect(config('pje.credenciais_padrao'))->toBeArray()
        ->toHaveKeys(['login', 'senha']);
});
