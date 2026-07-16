<?php

use App\Services\Callback\CallbackUrlValidator;
use App\Services\Callback\InvalidCallbackUrlException;

beforeEach(fn () => $this->v = new CallbackUrlValidator());

it('aceita https público', function () {
    // example.com é o domínio reservado pela IANA para documentação/testes e
    // sempre resolve para um IP público — 'cliente.exemplo.gov.br' (brief original)
    // não é um domínio registrado e não resolve, tornando o teste falho por
    // dependência de DNS, não por defeito do validador. Ver task-1-report.md.
    expect($this->v->ehValida('https://example.com/webhook'))->toBeTrue();
});

it('rejeita http', function () {
    expect($this->v->ehValida('http://cliente.exemplo.gov.br/webhook'))->toBeFalse();
});

it('rejeita URL malformada', function () {
    expect($this->v->ehValida('not a url'))->toBeFalse();
});

it('rejeita localhost e IPs internos', function () {
    foreach ([
        'https://localhost/webhook',
        'https://127.0.0.1/webhook',
        'https://10.1.2.3/webhook',
        'https://172.16.5.5/webhook',
        'https://192.168.0.10/webhook',
        'https://169.254.1.1/webhook',
    ] as $url) {
        expect($this->v->ehValida($url))->toBeFalse();
    }
});

it('assertValida lança em URL inválida', function () {
    $this->v->assertValida('http://localhost');
})->throws(InvalidCallbackUrlException::class);
