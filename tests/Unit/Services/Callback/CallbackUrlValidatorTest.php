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

// ---------- RF-17: hosts liberados fora de produção ----------

function liberarHostsCallback(string $ambiente, array $hosts): void
{
    config()->set('app.env', $ambiente);
    config()->set('pje.callback.hosts_liberados', $hosts);
}

it('aceita host liberado com http fora de produção, sem diferenciar maiúsculas e com qualquer porta e caminho', function () {
    liberarHostsCallback('local', ['host.docker.internal']);

    expect($this->v->ehValida('http://HOST.docker.internal:8006/x?y=1'))->toBeTrue()
        ->and($this->v->ehValida('https://host.docker.internal/mni/aviso?assinatura=x'))->toBeTrue()
        ->and($this->v->ehValida('http://host.docker.internal'))->toBeTrue();

    $this->v->assertValida('http://HOST.docker.internal:8006/x?y=1');
});

it('aceita host liberado mesmo quando ele é IP interno', function () {
    liberarHostsCallback('homolog', ['10.1.2.3', 'localhost']);

    expect($this->v->ehValida('http://10.1.2.3:8080/webhook'))->toBeTrue()
        ->and($this->v->ehValida('http://localhost/webhook'))->toBeTrue();
});

it('ignora a lista de hosts liberados em produção', function () {
    liberarHostsCallback('production', ['host.docker.internal']);

    expect($this->v->ehValida('http://HOST.docker.internal:8006/x?y=1'))->toBeFalse()
        ->and($this->v->ehValida('https://host.docker.internal/x'))->toBeFalse();
});

it('rejeita o host liberado com esquema que não seja http ou https', function () {
    liberarHostsCallback('local', ['host.docker.internal']);

    expect($this->v->ehValida('ftp://host.docker.internal/x'))->toBeFalse()
        ->and($this->v->ehValida('host.docker.internal/x'))->toBeFalse();
});

it('rejeita fora de produção com a lista vazia ou com outro host', function () {
    liberarHostsCallback('local', []);
    expect($this->v->ehValida('http://HOST.docker.internal:8006/x?y=1'))->toBeFalse();

    liberarHostsCallback('local', ['outro.host.interno']);
    expect($this->v->ehValida('http://HOST.docker.internal:8006/x?y=1'))->toBeFalse()
        ->and($this->v->ehValida('http://host.docker.internal.evil.example/x'))->toBeFalse();
});

it('assertValida lança para o host liberado em produção', function () {
    liberarHostsCallback('production', ['host.docker.internal']);

    $this->v->assertValida('http://host.docker.internal:8006/x');
})->throws(InvalidCallbackUrlException::class);
