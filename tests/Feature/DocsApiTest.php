<?php

it('exibe a documentação da API com spec-url https atrás de proxy TLS', function () {
    $this->get('/docs/api', ['X-Forwarded-Proto' => 'https'])
        ->assertOk()
        ->assertSee('spec-url="https://', escape: false);
});

it('serve a spec OpenAPI em yaml', function () {
    $this->get('/docs/api/openapi.yaml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/yaml');
});

it('serve a spec atualizada com o /processo/ocr e o webhook ocr_progresso (RNF-04)', function () {
    $resposta = $this->get('/docs/api/openapi.yaml')->assertOk();

    $conteudo = file_get_contents($resposta->baseResponse->getFile()->getPathname());

    expect($conteudo)->toContain('/processo/ocr:')
        ->and($conteudo)->toContain('ocr_progresso:')
        ->and($conteudo)->toContain('SolicitacaoDeOcr:');
});
