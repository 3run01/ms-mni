<?php

use App\Models\Tribunal;
use App\Services\Callback\CallbackNotifier;
use App\Services\Callback\InvalidCallbackUrlException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * RF-17 (D-03): fora de `production`, o callback_url de um host liberado
 * passa com `http` e IP interno. O validador é um só, então a regra vale
 * para toda rota com callback_url; aqui ela é provada no monitoramento,
 * que já existe, e no envio pelo CallbackNotifier.
 */

uses(DatabaseTransactions::class);

const CALLBACK_HOST_LIBERADO = 'http://HOST.docker.internal:8006/x?y=1';
const MENSAGEM_CALLBACK_URL_INVALIDA = 'O callback url deve ser uma URL https válida e não pode apontar para IP interno.';

beforeEach(function () {
    Queue::fake();
    criarTokenApi('tk-hosts-liberados');
    $this->headers = ['X-API-Token' => 'tk-hosts-liberados'];
    $this->tribunal = Tribunal::factory()->create(['ativo' => true]);
});

function configurarHostsLiberados(string $ambiente, array $hosts): void
{
    config()->set('app.env', $ambiente);
    config()->set('pje.callback.hosts_liberados', $hosts);
}

function payloadMonitoramentoHostLiberado(Tribunal $tribunal, string $callbackUrl = CALLBACK_HOST_LIBERADO): array
{
    return [
        'numero_processo' => '0000832-35.2024.4.01.3200',
        'tribunal_id' => $tribunal->id,
        'intervalo_horas' => 6,
        'callback_url' => $callbackUrl,
        'callback_token' => 'tok-cliente',
    ];
}

/**
 * Lê `config/pje.php` de novo com CALLBACK_HOSTS_LIBERADOS no valor pedido
 * (null = ausente), sem depender do .env da máquina.
 */
function hostsLiberadosDoAmbiente(?string $valor): array
{
    $antes = [$_ENV['CALLBACK_HOSTS_LIBERADOS'] ?? null, $_SERVER['CALLBACK_HOSTS_LIBERADOS'] ?? null, getenv('CALLBACK_HOSTS_LIBERADOS')];

    unset($_ENV['CALLBACK_HOSTS_LIBERADOS'], $_SERVER['CALLBACK_HOSTS_LIBERADOS']);
    putenv('CALLBACK_HOSTS_LIBERADOS');

    if ($valor !== null) {
        $_ENV['CALLBACK_HOSTS_LIBERADOS'] = $_SERVER['CALLBACK_HOSTS_LIBERADOS'] = $valor;
        putenv("CALLBACK_HOSTS_LIBERADOS={$valor}");
    }

    try {
        return (require config_path('pje.php'))['callback']['hosts_liberados'];
    } finally {
        unset($_ENV['CALLBACK_HOSTS_LIBERADOS'], $_SERVER['CALLBACK_HOSTS_LIBERADOS']);
        putenv('CALLBACK_HOSTS_LIBERADOS');

        if ($antes[0] !== null) {
            $_ENV['CALLBACK_HOSTS_LIBERADOS'] = $antes[0];
        }
        if ($antes[1] !== null) {
            $_SERVER['CALLBACK_HOSTS_LIBERADOS'] = $antes[1];
        }
        if ($antes[2] !== false) {
            putenv("CALLBACK_HOSTS_LIBERADOS={$antes[2]}");
        }
    }
}

it('a lista de hosts liberados é vazia por padrão', function () {
    expect(hostsLiberadosDoAmbiente(null))->toBe([])
        ->and(hostsLiberadosDoAmbiente(''))->toBe([]);
});

it('a lista de hosts liberados vem de CALLBACK_HOSTS_LIBERADOS separada por vírgula, com trim e sem vazios', function () {
    expect(hostsLiberadosDoAmbiente(' host.docker.internal , ,Outro.Host.Interno,'))
        ->toBe(['host.docker.internal', 'Outro.Host.Interno']);
});

it('POST /api/processo/monitoramentos aceita o host liberado com http em local', function () {
    configurarHostsLiberados('local', ['host.docker.internal']);

    $this->withHeaders($this->headers)
        ->postJson('/api/processo/monitoramentos', payloadMonitoramentoHostLiberado($this->tribunal))
        ->assertStatus(201);

    $this->assertDatabaseHas('processo_monitoramentos', ['callback_url' => CALLBACK_HOST_LIBERADO]);
});

it('POST /api/processo/monitoramentos recusa o host liberado em produção com a mensagem da regra CallbackUrl', function () {
    configurarHostsLiberados('production', ['host.docker.internal']);

    $this->withHeaders($this->headers)
        ->postJson('/api/processo/monitoramentos', payloadMonitoramentoHostLiberado($this->tribunal))
        ->assertStatus(422)
        ->assertJsonPath('errors.callback_url', [MENSAGEM_CALLBACK_URL_INVALIDA]);

    $this->assertDatabaseMissing('processo_monitoramentos', ['callback_url' => CALLBACK_HOST_LIBERADO]);
});

it('POST /api/processo/monitoramentos recusa em local com a lista vazia ou com outro host', function (array $hosts) {
    configurarHostsLiberados('local', $hosts);

    $this->withHeaders($this->headers)
        ->postJson('/api/processo/monitoramentos', payloadMonitoramentoHostLiberado($this->tribunal))
        ->assertStatus(422)
        ->assertJsonPath('errors.callback_url', [MENSAGEM_CALLBACK_URL_INVALIDA]);
})->with([
    'lista vazia' => [[]],
    'outro host' => [['outro.host.interno']],
]);

it('o CallbackNotifier envia ao host liberado fora de produção', function () {
    Http::fake(['*' => Http::response('', 200)]);
    configurarHostsLiberados('local', ['host.docker.internal']);

    $status = app(CallbackNotifier::class)->notificar(CALLBACK_HOST_LIBERADO, 'tok-cliente', ['evento' => 'teste']);

    expect($status)->toBe(200);
    // O cliente HTTP (Guzzle) normaliza o host para minúsculas.
    Http::assertSent(fn ($requisicao) => $requisicao->url() === 'http://host.docker.internal:8006/x?y=1'
        && $requisicao->hasHeader('X-API-Token', 'tok-cliente'));
});

it('o CallbackNotifier não envia ao host liberado em produção', function () {
    Http::fake(['*' => Http::response('', 200)]);
    configurarHostsLiberados('production', ['host.docker.internal']);

    expect(fn () => app(CallbackNotifier::class)->notificar(CALLBACK_HOST_LIBERADO, 'tok-cliente', ['evento' => 'teste']))
        ->toThrow(InvalidCallbackUrlException::class);

    Http::assertNothingSent();
});
