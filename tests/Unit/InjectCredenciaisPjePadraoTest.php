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
    definirCredenciaisPadrao(null, null);
});

it('injeta o par padrao quando a requisicao nao envia credenciais', function () {
    definirCredenciaisPadrao('env-login', 'env-senha');

    $request = passarPeloMiddlewarePje(['tribunal_id' => 1]);

    expect($request->input('login_pje'))->toBe('env-login')
        ->and($request->input('senha_pje'))->toBe('env-senha');
});

it('preserva as credenciais enviadas na requisicao', function () {
    definirCredenciaisPadrao('env-login', 'env-senha');

    $request = passarPeloMiddlewarePje([
        'login_pje' => 'req-login',
        'senha_pje' => 'req-senha',
    ]);

    expect($request->input('login_pje'))->toBe('req-login')
        ->and($request->input('senha_pje'))->toBe('req-senha');
});

it('substitui o par inteiro quando a requisicao envia so o login', function () {
    definirCredenciaisPadrao('env-login', 'env-senha');

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

it('descarta a credencial parcial da requisicao quando nao ha par padrao', function () {
    $request = passarPeloMiddlewarePje(['login_pje' => 'req-login']);

    expect($request->input('login_pje'))->toBeNull()
        ->and($request->input('senha_pje'))->toBeNull();
});

// ---------- marca de origem do par (credenciais_pje_do_cliente) ----------

it('marca o par como do cliente quando a requisicao envia as duas credenciais', function () {
    definirCredenciaisPadrao('env-login', 'env-senha');

    $request = passarPeloMiddlewarePje([
        'login_pje' => 'req-login',
        'senha_pje' => 'req-senha',
    ]);

    expect(InjectCredenciaisPjePadrao::ATRIBUTO_PAR_DO_CLIENTE)->toBe('credenciais_pje_do_cliente')
        ->and($request->attributes->get(InjectCredenciaisPjePadrao::ATRIBUTO_PAR_DO_CLIENTE))->toBeTrue();
});

it('marca o par como do cliente mesmo quando ele e igual ao par padrao', function () {
    definirCredenciaisPadrao('env-login', 'env-senha');

    $request = passarPeloMiddlewarePje([
        'login_pje' => 'env-login',
        'senha_pje' => 'env-senha',
    ]);

    expect($request->attributes->get(InjectCredenciaisPjePadrao::ATRIBUTO_PAR_DO_CLIENTE))->toBeTrue();
});

it('nao marca como do cliente o par padrao injetado', function () {
    definirCredenciaisPadrao('env-login', 'env-senha');

    $request = passarPeloMiddlewarePje(['tribunal_id' => 1]);

    expect($request->attributes->get(InjectCredenciaisPjePadrao::ATRIBUTO_PAR_DO_CLIENTE))->toBeFalse()
        ->and($request->input('login_pje'))->toBe('env-login');
});

it('nao marca como do cliente o par incompleto da requisicao', function (array $query) {
    definirCredenciaisPadrao('env-login', 'env-senha');

    $request = passarPeloMiddlewarePje($query);

    expect($request->attributes->get(InjectCredenciaisPjePadrao::ATRIBUTO_PAR_DO_CLIENTE))->toBeFalse();
})->with([
    'so o login' => [['login_pje' => 'req-login']],
    'so a senha' => [['senha_pje' => 'req-senha']],
    'senha vazia' => [['login_pje' => 'req-login', 'senha_pje' => '']],
]);

it('nao marca como do cliente quando nao ha par padrao configurado', function () {
    $request = passarPeloMiddlewarePje(['login_pje' => 'req-login']);

    expect($request->attributes->get(InjectCredenciaisPjePadrao::ATRIBUTO_PAR_DO_CLIENTE))->toBeFalse()
        ->and($request->input('login_pje'))->toBeNull()
        ->and($request->input('senha_pje'))->toBeNull();
});
