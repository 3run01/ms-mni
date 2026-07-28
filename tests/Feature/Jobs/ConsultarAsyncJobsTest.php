<?php

use App\Jobs\ConsultarDadosBasicosProcessoMNIJob;
use App\Jobs\ConsultarDocumentosProcessoMNIJob;
use App\Jobs\ConsultarMovimentosProcessoMNIJob;
use App\Models\Processo;
use App\Models\Tribunal;
use App\Services\Processo\ProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;

uses(DatabaseTransactions::class);

beforeEach(function () {
    // handle() notifica o callback do chamador via CallbackNotifier (Http::post); nunca deve sair para a rede real.
    Http::fake();
    criarTokenApi();
    definirCredenciaisPadrao(null, null);
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

    (new ConsultarDadosBasicosProcessoMNIJob(1, $numero, 'login-x', 'senha-x', 'https://example.com/webhook', 'tok-x'))->handle();
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

    (new ConsultarMovimentosProcessoMNIJob(1, $numero, 'login-x', 'senha-x', 'https://example.com/webhook', 'tok-x'))->handle();
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

    (new ConsultarDocumentosProcessoMNIJob(1, $numero, 'login-x', 'senha-x', 'https://example.com/webhook', 'tok-x'))->handle();
});

// ---------- Task 6: endpoints async exigem callback_url/callback_token e jobs notificam o chamador ----------

it('async dados-basicos exige callback e job notifica o chamador', function () {
    $this->withHeader('X-API-Token', 'tk-test')
        ->getJson('/api/processo/consultar/dados-basicos/async?login_pje=a&senha_pje=b&tribunal_id=1&numero_processo=X')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['callback_url', 'callback_token']);
});

it('job de dados-basicos POSTa callback ao concluir', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $this->mock(ProcessoService::class, function ($m) {
        $m->shouldReceive('consultarDadosBasicos')->andReturn(new Processo());
    });

    (new ConsultarDadosBasicosProcessoMNIJob(1, '123', 'a', 'b', 'https://example.com/webhook', 'tok'))->handle();

    Http::assertSent(fn ($r) => $r->url() === 'https://example.com/webhook'
        && $r->hasHeader('X-API-Token', 'tok')
        && $r['tipo'] === 'dados-basicos' && $r['status'] === 'concluido');
});

it('async movimentos exige callback e job notifica o chamador', function () {
    $this->withHeader('X-API-Token', 'tk-test')
        ->getJson('/api/processo/consultar/movimentos/async?login_pje=a&senha_pje=b&tribunal_id=1&numero_processo=X')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['callback_url', 'callback_token']);
});

it('job de movimentos POSTa callback ao concluir', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $this->mock(ProcessoService::class, function ($m) {
        $m->shouldReceive('consultarMovimentos')->andReturn(new Processo());
    });

    (new ConsultarMovimentosProcessoMNIJob(1, '123', 'a', 'b', 'https://example.com/webhook', 'tok'))->handle();

    Http::assertSent(fn ($r) => $r->url() === 'https://example.com/webhook'
        && $r->hasHeader('X-API-Token', 'tok')
        && $r['tipo'] === 'movimentos' && $r['status'] === 'concluido');
});

it('async documentos exige callback e job notifica o chamador', function () {
    $this->withHeader('X-API-Token', 'tk-test')
        ->getJson('/api/processo/consultar/documentos/async?login_pje=a&senha_pje=b&tribunal_id=1&numero_processo=X')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['callback_url', 'callback_token']);
});

it('job de documentos POSTa callback ao concluir', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $this->mock(ProcessoService::class, function ($m) {
        $m->shouldReceive('consultarDocumentos')->andReturn(new Processo());
    });

    (new ConsultarDocumentosProcessoMNIJob(1, '123', 'a', 'b', 'https://example.com/webhook', 'tok'))->handle();

    Http::assertSent(fn ($r) => $r->url() === 'https://example.com/webhook'
        && $r->hasHeader('X-API-Token', 'tok')
        && $r['tipo'] === 'documentos' && $r['status'] === 'concluido');
});
