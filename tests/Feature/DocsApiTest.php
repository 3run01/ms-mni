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
