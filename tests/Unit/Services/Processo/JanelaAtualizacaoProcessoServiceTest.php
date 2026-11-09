<?php

use App\Services\Processo\JanelaAtualizacaoProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * Janela de atualização do processo (T03, DP-04; RF-15, RNF-01, RNF-04):
 * 600 s por (tribunal_id, numero_processo), aberta por uma operação única
 * de "gravar se ausente", sem o par do PJe.
 */

uses(DatabaseTransactions::class);

const NUMERO_JANELA_ATUALIZACAO = '00002945820238030011';
const LOGIN_JANELA_ATUALIZACAO = 'login-par-b-7c2';
const SENHA_JANELA_ATUALIZACAO = 'senha-par-b-4d9';

beforeEach(function () {
    // O par da chamada fica no config, ao alcance de qualquer código que
    // tentasse gravá-lo; a janela nunca o recebe.
    definirCredenciaisPadrao(LOGIN_JANELA_ATUALIZACAO, SENHA_JANELA_ATUALIZACAO);
});

/**
 * Sequência de RF-15 sobre o store padrão em uso: abre, suprime até 599 s,
 * reabre depois de 600 s e mantém uma janela por linha.
 */
function conferirSequenciaDaJanela(JanelaAtualizacaoProcessoService $janela, string $numero): void
{
    expect($janela->abrir(1, $numero))->toBeTrue()
        ->and($janela->abrir(1, $numero))->toBeFalse();

    test()->travel(599)->seconds();

    expect($janela->abrir(1, $numero))->toBeFalse();

    test()->travel(2)->seconds();

    expect($janela->abrir(1, $numero))->toBeTrue()
        ->and($janela->abrir(2, $numero))->toBeTrue()
        ->and($janela->abrir(2, $numero))->toBeFalse()
        ->and($janela->abrir(1, $numero))->toBeFalse();
}

it('a chave é baixar-processo:{tribunal_id}:{numero_processo}', function () {
    expect(JanelaAtualizacaoProcessoService::chave(2, NUMERO_JANELA_ATUALIZACAO))
        ->toBe('baixar-processo:2:'.NUMERO_JANELA_ATUALIZACAO);
});

it('no store array abre, suprime por 600 s, reabre depois e separa as linhas', function () {
    expect(config('cache.default'))->toBe('array');

    conferirSequenciaDaJanela(new JanelaAtualizacaoProcessoService(), NUMERO_JANELA_ATUALIZACAO);
});

it('a janela de uma linha não fecha a mesma numeração em outra linha', function () {
    $janela = new JanelaAtualizacaoProcessoService();

    expect($janela->abrir(1, NUMERO_JANELA_ATUALIZACAO))->toBeTrue()
        ->and($janela->abrir(2, NUMERO_JANELA_ATUALIZACAO))->toBeTrue()
        ->and($janela->abrir(1, '00000011120268030001'))->toBeTrue();
});

it('no store database faz a mesma sequência, inclusive a reabertura depois da linha vencida', function () {
    config(['cache.default' => 'database']);
    $numero = '9'.substr(NUMERO_JANELA_ATUALIZACAO, 1);

    Cache::store('database')->forget(JanelaAtualizacaoProcessoService::chave(1, $numero));
    Cache::store('database')->forget(JanelaAtualizacaoProcessoService::chave(2, $numero));

    $janela = new JanelaAtualizacaoProcessoService();

    expect($janela->abrir(1, $numero))->toBeTrue();

    $tabela = config('cache.stores.database.table');
    $linha = DB::table($tabela)
        ->where('key', 'like', '%'.JanelaAtualizacaoProcessoService::chave(1, $numero))
        ->sole();

    expect($linha->key)->toEndWith(JanelaAtualizacaoProcessoService::chave(1, $numero))
        ->and(unserialize($linha->value))->toBeTrue()
        ->and((int) $linha->expiration)->toBe(now()->getTimestamp() + 600)
        ->and($linha->key.$linha->value)->not->toContain(LOGIN_JANELA_ATUALIZACAO)
        ->and($linha->key.$linha->value)->not->toContain(SENHA_JANELA_ATUALIZACAO);

    expect($janela->abrir(1, $numero))->toBeFalse();

    $this->travel(599)->seconds();

    expect($janela->abrir(1, $numero))->toBeFalse();

    $this->travel(2)->seconds();

    // A linha vencida é apagada pelo próprio add, e a janela reabre.
    expect($janela->abrir(1, $numero))->toBeTrue()
        ->and((int) DB::table($tabela)
            ->where('key', 'like', '%'.JanelaAtualizacaoProcessoService::chave(1, $numero))
            ->sole()->expiration)->toBe(now()->getTimestamp() + 600)
        ->and($janela->abrir(2, $numero))->toBeTrue()
        ->and($janela->abrir(2, $numero))->toBeFalse();

    $conteudo = DB::table($tabela)
        ->where('key', 'like', '%baixar-processo:%:'.$numero)
        ->get()
        ->map(fn ($registro) => $registro->key.$registro->value)
        ->implode("\n");

    expect($conteudo)->not->toContain(LOGIN_JANELA_ATUALIZACAO)
        ->and($conteudo)->not->toContain(SENHA_JANELA_ATUALIZACAO);
});

it('confere e abre com 1 add, sem has, get nem put', function () {
    Cache::spy();

    (new JanelaAtualizacaoProcessoService())->abrir(1, NUMERO_JANELA_ATUALIZACAO);

    Cache::shouldHaveReceived('add')
        ->once()
        ->with(JanelaAtualizacaoProcessoService::chave(1, NUMERO_JANELA_ATUALIZACAO), true, 600);
    Cache::shouldNotHaveReceived('has');
    Cache::shouldNotHaveReceived('get');
    Cache::shouldNotHaveReceived('put');
});

it('lê a validade da configuração a cada chamada', function () {
    config(['pje.atualizacao_processo.janela_segundos' => 30]);
    $janela = new JanelaAtualizacaoProcessoService();

    expect($janela->abrir(1, NUMERO_JANELA_ATUALIZACAO))->toBeTrue();

    $this->travel(31)->seconds();

    expect($janela->abrir(1, NUMERO_JANELA_ATUALIZACAO))->toBeTrue();

    config(['pje.atualizacao_processo.janela_segundos' => 0]);
    Cache::spy();

    $janela->abrir(2, NUMERO_JANELA_ATUALIZACAO);

    // Nunca uma validade nula ou negativa, que faria o add devolver false.
    Cache::shouldHaveReceived('add')
        ->once()
        ->with(JanelaAtualizacaoProcessoService::chave(2, NUMERO_JANELA_ATUALIZACAO), true, 1);
});

it('com o cache lançando exceção devolve false e registra 1 warning sem o par', function () {
    Cache::shouldReceive('add')
        ->once()
        ->andThrow(new RuntimeException('falha do driver com '.LOGIN_JANELA_ATUALIZACAO.' e '.SENHA_JANELA_ATUALIZACAO));
    Log::spy();

    expect((new JanelaAtualizacaoProcessoService())->abrir(2, NUMERO_JANELA_ATUALIZACAO))->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $mensagem, array $contexto) {
            $registro = $mensagem.json_encode($contexto);

            return str_starts_with($mensagem, '[JanelaAtualizacao]')
                && $contexto === [
                    'tribunal_id' => 2,
                    'numero_processo' => NUMERO_JANELA_ATUALIZACAO,
                    'erro' => 'RuntimeException',
                ]
                && ! str_contains($registro, LOGIN_JANELA_ATUALIZACAO)
                && ! str_contains($registro, SENHA_JANELA_ATUALIZACAO);
        });
});

it('a assinatura de abrir não recebe o par do PJe', function () {
    $parametros = array_map(
        fn (ReflectionParameter $parametro) => $parametro->getName(),
        (new ReflectionMethod(JanelaAtualizacaoProcessoService::class, 'abrir'))->getParameters(),
    );

    expect($parametros)->toBe(['tribunalId', 'numeroProcesso']);
});
