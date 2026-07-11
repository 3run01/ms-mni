<?php

use App\Jobs\BaixarProcessoMNIJob;
use App\Models\Tribunal;
use App\Services\Processo\ProcessoService;

it('handle() passa login, senha e data_referencia nos slots corretos do ProcessoService', function () {
    $tribunal = Tribunal::factory()->make(['id' => 1]);
    $numero = '0600125-81.2024.8.03.0003';

    $this->mock(ProcessoService::class, function ($mock) use ($tribunal, $numero) {
        // 4 args, SEM data_referencia: login/senha nos slots 3 e 4.
        $mock->shouldReceive('consultarDadosBasicos')
            ->once()
            ->withArgs(function (...$args) use ($tribunal, $numero) {
                return $args === [$tribunal, $numero, 'login-x', 'senha-x'];
            });

        // 5 args: data_referencia no slot 5.
        $mock->shouldReceive('consultarMovimentos')
            ->once()
            ->withArgs(function (...$args) use ($tribunal, $numero) {
                return $args === [$tribunal, $numero, 'login-x', 'senha-x', 'data-ref-x'];
            });

        // 5 args: data_referencia no slot 5.
        $mock->shouldReceive('consultarDocumentos')
            ->once()
            ->withArgs(function (...$args) use ($tribunal, $numero) {
                return $args === [$tribunal, $numero, 'login-x', 'senha-x', 'data-ref-x'];
            });
    });

    (new BaixarProcessoMNIJob($tribunal, $numero, 'login-x', 'senha-x', 'data-ref-x'))->handle();
});
