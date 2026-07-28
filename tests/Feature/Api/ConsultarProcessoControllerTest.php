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
    criarTokenApi();
    // isola do .env da máquina: cada teste declara o par padrão que quer
    definirCredenciaisPadrao(null, null);
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

it('consultar sem credenciais usa o par padrao do .env', function () {
    Queue::fake();
    definirCredenciaisPadrao('env-login', 'env-senha');
    criarProcessoParaConsulta('0600125-81.2024.8.03.0003');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003')
        ->assertOk();

    Queue::assertPushed(
        BaixarProcessoMNIJob::class,
        fn ($job) => $job->login_pje === 'env-login' && $job->senha_pje === 'env-senha'
    );
});

it('consultar com par incompleto usa o par padrao do .env inteiro', function () {
    Queue::fake();
    definirCredenciaisPadrao('env-login', 'env-senha');
    criarProcessoParaConsulta('0600125-81.2024.8.03.0003');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&login_pje=usuario')
        ->assertOk();

    Queue::assertPushed(
        BaixarProcessoMNIJob::class,
        fn ($job) => $job->login_pje === 'env-login' && $job->senha_pje === 'env-senha'
    );
});

it('consultar com credenciais na requisicao ignora o par padrao do .env', function () {
    Queue::fake();
    definirCredenciaisPadrao('env-login', 'env-senha');
    criarProcessoParaConsulta('0600125-81.2024.8.03.0003');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&login_pje=req-login&senha_pje=req-senha')
        ->assertOk();

    Queue::assertPushed(
        BaixarProcessoMNIJob::class,
        fn ($job) => $job->login_pje === 'req-login' && $job->senha_pje === 'req-senha'
    );
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

it('visualizar sem credenciais e sem par padrao repassa null ao ProcessoService', function () {
    $this->mock(ProcessoService::class, function ($mock) {
        $mock->shouldReceive('consultarNumero')
            ->once()
            ->withArgs(fn ($tribunal, $numero, $login, $senha) => $login === null && $senha === null);
    });

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/visualizar?tribunal_id=1&numero_processo=9999999-99.2024.8.03.9999')
        ->assertOk();
});

it('visualizar processo existente sem credenciais agenda refresh com o par padrao do .env', function () {
    Queue::fake();
    definirCredenciaisPadrao('env-login', 'env-senha');
    criarProcessoParaConsulta('0600125-81.2024.8.03.0003');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/visualizar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003')
        ->assertOk();

    Queue::assertPushed(
        BaixarProcessoMNIJob::class,
        fn ($job) => $job->login_pje === 'env-login' && $job->senha_pje === 'env-senha'
    );
});

it('visualizar com credenciais e processo existente retorna 200', function () {
    Queue::fake();
    criarProcessoParaConsulta('0600125-81.2024.8.03.0003');

    $response = $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/visualizar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&login_pje=usuario&senha_pje=segredo');

    $response->assertOk();
    Queue::assertPushed(BaixarProcessoMNIJob::class);
});

it('visualizar processo existente agenda refresh com as credenciais do payload', function () {
    Queue::fake();
    criarProcessoParaConsulta('0600125-81.2024.8.03.0003');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/visualizar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&login_pje=u-pje&senha_pje=s-pje')
        ->assertOk();

    Queue::assertPushed(
        BaixarProcessoMNIJob::class,
        fn ($job) => $job->login_pje === 'u-pje' && $job->senha_pje === 's-pje'
    );
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

// ---------- endpoints com credenciais opcionais ----------

it('dados-basicos sem credenciais usa o par padrao do .env', function () {
    definirCredenciaisPadrao('env-login', 'env-senha');

    $this->mock(ProcessoService::class, function ($mock) {
        $mock->shouldReceive('consultarDadosBasicos')
            ->once()
            ->withArgs(fn ($tribunal, $numero, $login, $senha) => $login === 'env-login' && $senha === 'env-senha')
            ->andReturn(new Processo());
    });

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/dados-basicos?tribunal_id=1&numero_processo=9999999-99.2024.8.03.9999')
        ->assertOk();
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

it('movimentos sem credenciais usa o par padrao do .env', function () {
    definirCredenciaisPadrao('env-login', 'env-senha');
    $processo = criarProcessoParaConsulta('0600125-81.2024.8.03.0003');
    $processo->setRelation('movimentos', collect());

    $this->mock(ProcessoService::class, function ($mock) use ($processo) {
        $mock->shouldReceive('consultarMovimentos')
            ->once()
            ->withArgs(fn ($tribunal, $numero, $login, $senha, $dataRef) => $login === 'env-login' && $senha === 'env-senha')
            ->andReturn($processo);
    });

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/movimentos/listar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003')
        ->assertOk();
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

it('dados-basicos async sem credenciais despacha job com o par padrao do .env', function () {
    Queue::fake();
    definirCredenciaisPadrao('env-login', 'env-senha');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar/dados-basicos/async?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&callback_url=https://example.com/webhook&callback_token=tok-x')
        ->assertOk();

    Queue::assertPushed(
        ConsultarDadosBasicosProcessoMNIJob::class,
        fn ($job) => $job->login_pje === 'env-login' && $job->senha_pje === 'env-senha'
    );
});

it('dados-basicos async sem callback continua retornando 422', function () {
    definirCredenciaisPadrao('env-login', 'env-senha');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar/dados-basicos/async?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['callback_url', 'callback_token'])
        ->assertJsonMissingValidationErrors(['login_pje', 'senha_pje']);
});

it('dados-basicos async despacha job com as credenciais do payload', function () {
    Queue::fake();

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar/dados-basicos/async?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&login_pje=u-pje&senha_pje=s-pje&callback_url=https://example.com/webhook&callback_token=tok-x')
        ->assertOk();

    Queue::assertPushed(
        ConsultarDadosBasicosProcessoMNIJob::class,
        fn ($job) => $job->login_pje === 'u-pje' && $job->senha_pje === 's-pje'
            && $job->callback_url === 'https://example.com/webhook' && $job->callback_token === 'tok-x'
    );
});

it('movimentos async sem credenciais despacha job com o par padrao do .env', function () {
    Queue::fake();
    definirCredenciaisPadrao('env-login', 'env-senha');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar/movimentos/async?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&callback_url=https://example.com/webhook&callback_token=tok-x')
        ->assertOk();

    Queue::assertPushed(
        ConsultarMovimentosProcessoMNIJob::class,
        fn ($job) => $job->login_pje === 'env-login' && $job->senha_pje === 'env-senha'
    );
});

it('movimentos async despacha job com as credenciais do payload', function () {
    Queue::fake();

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar/movimentos/async?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&login_pje=u-pje&senha_pje=s-pje&callback_url=https://example.com/webhook&callback_token=tok-x')
        ->assertOk();

    Queue::assertPushed(
        ConsultarMovimentosProcessoMNIJob::class,
        fn ($job) => $job->login_pje === 'u-pje' && $job->senha_pje === 's-pje'
            && $job->callback_url === 'https://example.com/webhook' && $job->callback_token === 'tok-x'
    );
});
