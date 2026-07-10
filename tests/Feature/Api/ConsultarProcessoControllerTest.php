<?php

use App\Jobs\BaixarProcessoMNIJob;
use App\Jobs\ConsultarDadosBasicosProcessoMNIJob;
use App\Jobs\ConsultarMovimentosProcessoMNIJob;
use App\Models\Processo;
use App\Services\Processo\ProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;

uses(DatabaseTransactions::class);

beforeEach(function () {
    config()->set('services.api.token', 'tk-test');
});

function criarProcessoParaConsulta(string $numero, int $tribunalId = 1): Processo
{
    return Processo::create([
        'numero_processo' => cleanNumeroProcesso($numero),
        'tribunal_id' => $tribunalId,
        'valor_causa' => '0.00',
    ]);
}

// ---------- GET /api/processo/consultar ----------

it('consultar sem login_pje e senha_pje retorna 422', function () {
    $response = $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['login_pje', 'senha_pje']);
});

it('consultar com login_pje mas sem senha_pje retorna 422', function () {
    $response = $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&login_pje=usuario');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['senha_pje'])
        ->assertJsonMissingValidationErrors(['login_pje']);
});

it('consultar com credenciais e processo existente retorna 200 e agenda refresh', function () {
    Queue::fake();
    criarProcessoParaConsulta('0600125-81.2024.8.03.0003');

    $response = $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&login_pje=usuario&senha_pje=segredo');

    $response->assertOk();
    Queue::assertPushed(BaixarProcessoMNIJob::class);
});

it('consultar com processo inexistente repassa credenciais ao ProcessoService', function () {
    $this->mock(ProcessoService::class, function ($mock) {
        $mock->shouldReceive('consultarNumero')
            ->once()
            ->withArgs(function ($tribunal, $numero, $login, $senha) {
                return $login === 'usuario-pje' && $senha === 'segredo-pje';
            });
    });

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar?tribunal_id=1&numero_processo=9999999-99.2024.8.03.9999&login_pje=usuario-pje&senha_pje=segredo-pje')
        ->assertOk();
});

// ---------- GET /api/processo/visualizar ----------

it('visualizar sem credenciais retorna 422 mesmo com processo em banco', function () {
    criarProcessoParaConsulta('0600125-81.2024.8.03.0003');

    $response = $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/visualizar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['login_pje', 'senha_pje']);
});

it('visualizar com credenciais e processo existente retorna 200', function () {
    Queue::fake();
    criarProcessoParaConsulta('0600125-81.2024.8.03.0003');

    $response = $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/visualizar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&login_pje=usuario&senha_pje=segredo');

    $response->assertOk();
    Queue::assertPushed(BaixarProcessoMNIJob::class);
});

it('visualizar com processo inexistente repassa credenciais ao ProcessoService', function () {
    $this->mock(ProcessoService::class, function ($mock) {
        $mock->shouldReceive('consultarNumero')
            ->once()
            ->withArgs(function ($tribunal, $numero, $login, $senha) {
                return $login === 'usuario-pje' && $senha === 'segredo-pje';
            });
    });

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/visualizar?tribunal_id=1&numero_processo=9999999-99.2024.8.03.9999&login_pje=usuario-pje&senha_pje=segredo-pje')
        ->assertOk();
});

// ---------- endpoints que agora EXIGEM credenciais ----------

it('dados-basicos sem credenciais retorna 422', function () {
    criarProcessoParaConsulta('0600125-81.2024.8.03.0003');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/dados-basicos?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['login_pje', 'senha_pje']);
});

it('dados-basicos repassa credenciais do payload ao ProcessoService', function () {
    $this->mock(ProcessoService::class, function ($mock) {
        $mock->shouldReceive('consultarDadosBasicos')
            ->once()
            ->withArgs(fn ($tribunal, $numero, $login, $senha) => $login === 'u-pje' && $senha === 's-pje')
            ->andReturn(new Processo());
    });

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/dados-basicos?tribunal_id=1&numero_processo=9999999-99.2024.8.03.9999&login_pje=u-pje&senha_pje=s-pje')
        ->assertOk();
});

it('movimentos sem credenciais retorna 422', function () {
    criarProcessoParaConsulta('0600125-81.2024.8.03.0003');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/movimentos/listar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['login_pje', 'senha_pje']);
});

it('movimentos repassa credenciais do payload ao ProcessoService', function () {
    $processo = criarProcessoParaConsulta('0600125-81.2024.8.03.0003');
    $processo->setRelation('movimentos', collect());

    $this->mock(ProcessoService::class, function ($mock) use ($processo) {
        $mock->shouldReceive('consultarMovimentos')
            ->once()
            ->withArgs(fn ($tribunal, $numero, $login, $senha, $dataRef) => $login === 'u-pje' && $senha === 's-pje')
            ->andReturn($processo);
    });

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/movimentos/listar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&login_pje=u-pje&senha_pje=s-pje')
        ->assertOk();
});

// ---------- endpoints async ----------

it('dados-basicos async sem credenciais retorna 422', function () {
    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar/dados-basicos/async?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['login_pje', 'senha_pje']);
});

it('dados-basicos async despacha job com as credenciais do payload', function () {
    Queue::fake();

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar/dados-basicos/async?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&login_pje=u-pje&senha_pje=s-pje');

    Queue::assertPushed(
        ConsultarDadosBasicosProcessoMNIJob::class,
        fn ($job) => $job->login_pje === 'u-pje' && $job->senha_pje === 's-pje'
    );
});

it('movimentos async sem credenciais retorna 422', function () {
    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar/movimentos/async?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['login_pje', 'senha_pje']);
});

it('movimentos async despacha job com as credenciais do payload', function () {
    Queue::fake();

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar/movimentos/async?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&login_pje=u-pje&senha_pje=s-pje');

    Queue::assertPushed(
        ConsultarMovimentosProcessoMNIJob::class,
        fn ($job) => $job->login_pje === 'u-pje' && $job->senha_pje === 's-pje'
    );
});
