<?php

use App\Services\Processo\TravaDownloadDocumento;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    // phpunit.xml define CACHE_STORE=array; a trava usa o store padrão
    expect(config('cache.default'))->toBe('array');

    $this->trava = new TravaDownloadDocumento();
});

it('nega a segunda aquisicao do mesmo documento por outro dono', function () {
    expect($this->trava->adquirir(501, 'lote-a'))->toBeTrue()
        ->and($this->trava->adquirir(501, 'lote-b'))->toBeFalse();
});

it('trava cada documento de forma independente', function () {
    expect($this->trava->adquirir(501, 'lote-a'))->toBeTrue()
        ->and($this->trava->adquirir(502, 'lote-b'))->toBeTrue();
});

it('permite readquirir depois de liberar com o dono certo', function () {
    $this->trava->adquirir(501, 'lote-a');

    $this->trava->liberar(501, 'lote-a');

    expect($this->trava->adquirir(501, 'lote-b'))->toBeTrue();
});

it('nao libera a trava com o dono errado', function () {
    $this->trava->adquirir(501, 'lote-a');

    $this->trava->liberar(501, 'lote-b');

    expect($this->trava->adquirir(501, 'lote-c'))->toBeFalse();
});

it('expira depois de 1800 s', function () {
    $this->trava->adquirir(501, 'lote-a');

    $this->travel(1799)->seconds();
    expect($this->trava->adquirir(501, 'lote-b'))->toBeFalse();

    $this->travel(2)->seconds();
    expect($this->trava->adquirir(501, 'lote-b'))->toBeTrue();
});

it('adquire depois de 1801 s mesmo sem liberar', function () {
    $this->trava->adquirir(501, 'lote-a');

    $this->travel(1801)->seconds();

    expect($this->trava->adquirir(501, 'lote-b'))->toBeTrue()
        ->and(TravaDownloadDocumento::TTL_SEGUNDOS)->toBe(1800);
});

it('nao derruba a trava readquirida por outro lote depois de expirar', function () {
    $this->trava->adquirir(501, 'lote-a');
    $this->travel(1801)->seconds();
    $this->trava->adquirir(501, 'lote-b');

    $this->trava->liberar(501, 'lote-a');

    expect($this->trava->adquirir(501, 'lote-c'))->toBeFalse();
});

it('libera uma lista de documentos do mesmo dono', function () {
    foreach ([601, 602, 603] as $id) {
        $this->trava->adquirir($id, 'lote-a');
    }
    $this->trava->adquirir(604, 'lote-x');

    $this->trava->liberarTodas([601, 602, 603, 604], 'lote-a');

    expect($this->trava->adquirir(601, 'lote-b'))->toBeTrue()
        ->and($this->trava->adquirir(602, 'lote-b'))->toBeTrue()
        ->and($this->trava->adquirir(603, 'lote-b'))->toBeTrue()
        ->and($this->trava->adquirir(604, 'lote-b'))->toBeFalse();
});

it('usa a chave mni-download:documento:{id} no cache store padrao', function () {
    $this->trava->adquirir(701, 'lote-a');

    expect(Cache::lock('mni-download:documento:701', 10, 'outro')->get())->toBeFalse()
        ->and(Cache::restoreLock('mni-download:documento:701', 'lote-a')->isOwnedByCurrentProcess())->toBeTrue();
});
