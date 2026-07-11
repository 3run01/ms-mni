<?php

use App\Jobs\ConsultarDadosBasicosProcessoMNIJob;
use App\Jobs\ConsultarDocumentosProcessoMNIJob;
use App\Jobs\ConsultarMovimentosProcessoMNIJob;
use App\Models\Tribunal;
use App\Services\Processo\ProcessoService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // handle() faz Http::get(...) no final (webhook); nunca deve sair para a rede real.
    Http::fake();
});

it('ConsultarDadosBasicosProcessoMNIJob::handle() repassa login_pje/senha_pje para consultarDadosBasicos', function () {
    $numero = '0600125-81.2024.8.03.0003';

    $this->mock(ProcessoService::class, function ($mock) use ($numero) {
        $mock->shouldReceive('consultarDadosBasicos')
            ->once()
            ->withArgs(function (...$args) use ($numero) {
                return count($args) === 4
                    && $args[0] instanceof Tribunal
                    && $args[0]->id === 1
                    && $args[1] === $numero
                    && $args[2] === 'login-x'
                    && $args[3] === 'senha-x';
            });
    });

    (new ConsultarDadosBasicosProcessoMNIJob(1, $numero, 'login-x', 'senha-x'))->handle();
});

it('ConsultarMovimentosProcessoMNIJob::handle() repassa login_pje/senha_pje para consultarMovimentos sem data_referencia', function () {
    $numero = '0600125-81.2024.8.03.0003';

    $this->mock(ProcessoService::class, function ($mock) use ($numero) {
        $mock->shouldReceive('consultarMovimentos')
            ->once()
            ->withArgs(function (...$args) use ($numero) {
                // 4 args: este job não repassa data_referencia ao ProcessoService.
                return count($args) === 4
                    && $args[0] instanceof Tribunal
                    && $args[0]->id === 1
                    && $args[1] === $numero
                    && $args[2] === 'login-x'
                    && $args[3] === 'senha-x';
            });
    });

    (new ConsultarMovimentosProcessoMNIJob(1, $numero, 'login-x', 'senha-x'))->handle();
});

it('ConsultarDocumentosProcessoMNIJob::handle() repassa login_pje/senha_pje para consultarDocumentos sem data_referencia', function () {
    $numero = '0600125-81.2024.8.03.0003';

    $this->mock(ProcessoService::class, function ($mock) use ($numero) {
        $mock->shouldReceive('consultarDocumentos')
            ->once()
            ->withArgs(function (...$args) use ($numero) {
                // 4 args: este job não repassa data_referencia ao ProcessoService.
                return count($args) === 4
                    && $args[0] instanceof Tribunal
                    && $args[0]->id === 1
                    && $args[1] === $numero
                    && $args[2] === 'login-x'
                    && $args[3] === 'senha-x';
            });
    });

    (new ConsultarDocumentosProcessoMNIJob(1, $numero, 'login-x', 'senha-x'))->handle();
});
