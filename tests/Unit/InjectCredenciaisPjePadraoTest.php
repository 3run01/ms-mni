<?php

use App\Http\Middleware\InjectCredenciaisPjePadrao;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Roda o middleware sobre uma requisição GET fake e devolve o request
 * como ele chegou no próximo handler.
 */
function passarPeloMiddlewarePje(array $query): Request
{
    $request = Request::create('/api/processo/consultar', 'GET', $query);
    $capturado = null;

    (new InjectCredenciaisPjePadrao())->handle($request, function (Request $r) use (&$capturado) {
        $capturado = $r;

        return new Response();
    });

    return $capturado;
}

beforeEach(function () {
    config()->set('pje.credenciais_padrao.login', null);
    config()->set('pje.credenciais_padrao.senha', null);
});

it('injeta o par padrao quando a requisicao nao envia credenciais', function () {
    config()->set('pje.credenciais_padrao.login', 'env-login');
    config()->set('pje.credenciais_padrao.senha', 'env-senha');

    $request = passarPeloMiddlewarePje(['tribunal_id' => 1]);

    expect($request->input('login_pje'))->toBe('env-login')
        ->and($request->input('senha_pje'))->toBe('env-senha');
});

it('preserva as credenciais enviadas na requisicao', function () {
    config()->set('pje.credenciais_padrao.login', 'env-login');
    config()->set('pje.credenciais_padrao.senha', 'env-senha');

    $request = passarPeloMiddlewarePje([
        'login_pje' => 'req-login',
        'senha_pje' => 'req-senha',
    ]);

    expect($request->input('login_pje'))->toBe('req-login')
        ->and($request->input('senha_pje'))->toBe('req-senha');
});

it('substitui o par inteiro quando a requisicao envia so o login', function () {
    config()->set('pje.credenciais_padrao.login', 'env-login');
    config()->set('pje.credenciais_padrao.senha', 'env-senha');

    $request = passarPeloMiddlewarePje(['login_pje' => 'req-login']);

    expect($request->input('login_pje'))->toBe('env-login')
        ->and($request->input('senha_pje'))->toBe('env-senha');
});

it('nao injeta nada quando o par padrao esta vazio', function () {
    $request = passarPeloMiddlewarePje(['tribunal_id' => 1]);

    expect($request->input('login_pje'))->toBeNull()
        ->and($request->input('senha_pje'))->toBeNull();
});

it('nao injeta nada quando so o login padrao esta configurado', function () {
    config()->set('pje.credenciais_padrao.login', 'env-login');

    $request = passarPeloMiddlewarePje(['tribunal_id' => 1]);

    expect($request->input('login_pje'))->toBeNull()
        ->and($request->input('senha_pje'))->toBeNull();
});
