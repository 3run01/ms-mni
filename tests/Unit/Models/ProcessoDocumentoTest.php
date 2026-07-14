<?php

use App\Models\ProcessoDocumento;

it('temConteudoHtml retorna false sem coluna e sem path', function () {
    $documento = new ProcessoDocumento();

    expect($documento->temConteudoHtml())->toBeFalse();
});

it('temConteudoHtml retorna true com conteudo_html preenchido (legado)', function () {
    $documento = new ProcessoDocumento();
    $documento->conteudo_html = '<html><body>Ola</body></html>';

    expect($documento->temConteudoHtml())->toBeTrue();
});

it('temConteudoHtml retorna true com path_html preenchido', function () {
    $documento = new ProcessoDocumento(['path_html' => 'documentos-processos/123/456.html']);

    expect($documento->temConteudoHtml())->toBeTrue();
});

it('temConteudoHtml retorna true com ambos preenchidos', function () {
    $documento = new ProcessoDocumento(['path_html' => 'documentos-processos/123/456.html']);
    $documento->conteudo_html = '<html></html>';

    expect($documento->temConteudoHtml())->toBeTrue();
});

it('nao serializa conteudo_html nem path_html', function () {
    $documento = new ProcessoDocumento([
        'descricao' => 'Peticao',
        'path_html' => 'documentos-processos/123/456.html',
    ]);
    $documento->conteudo_html = '<html><body>Pesado</body></html>';

    $array = $documento->toArray();

    expect($array)->not->toHaveKey('conteudo_html')
        ->and($array)->not->toHaveKey('path_html')
        ->and($array)->toHaveKey('descricao');
});
