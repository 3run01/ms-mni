<?php

it('expoe as chaves de credenciais padrao do PJe', function () {
    expect(config('pje.credenciais_padrao'))->toBeArray()
        ->toHaveKeys(['login', 'senha']);
});

it('mapeia as credenciais padrao para as variaveis de ambiente corretas', function () {
    expect(config('pje.credenciais_padrao.login'))->toBe(env('PJE_LOGIN_PADRAO'))
        ->and(config('pje.credenciais_padrao.senha'))->toBe(env('PJE_SENHA_PADRAO'));
});

it('expoe as chaves do download de documentos em lote', function () {
    expect(config('pje.download_documentos'))->toBeArray()
        ->toHaveKeys(['tamanho_lote', 'max_paginas']);
});

it('mapeia o download em lote para as variaveis de ambiente corretas', function () {
    expect(config('pje.download_documentos.tamanho_lote'))->toBe(env('PJE_DOWNLOAD_TAMANHO_LOTE', 20))
        ->and(config('pje.download_documentos.max_paginas'))->toBe(env('PJE_DOWNLOAD_MAX_PAGINAS'));
});

it('documenta as variaveis do download em lote no .env.example', function () {
    $exemplo = file_get_contents(base_path('.env.example'));

    expect($exemplo)->toContain('PJE_DOWNLOAD_TAMANHO_LOTE')
        ->and($exemplo)->toContain('PJE_DOWNLOAD_MAX_PAGINAS');
});
