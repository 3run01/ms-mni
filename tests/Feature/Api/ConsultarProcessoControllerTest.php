<?php

use App\Jobs\BaixarProcessoMNIJob;
use App\Jobs\ConsultarDadosBasicosProcessoMNIJob;
use App\Jobs\ConsultarMovimentosProcessoMNIJob;
use App\Models\Processo;
use App\Models\Tribunal;
use App\Services\Processo\ProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
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

// ---------- erros MNI em /api/processo/visualizar ----------
// Caracterização do comportamento atual: nenhuma falha MNI devolve `codigo`.
// O tribunal novo garante processo ausente no banco (salvo onde o teste o cria).

const NUMERO_PROCESSO_VISUALIZAR_ERRO_MNI = '99999901120268030001';
const LOGIN_RECUSADO_VISUALIZAR_ERRO_MNI = 'Erro ao realizar login via MNI. exception invoking: loginFailed';
const LOGIN_PADRONIZADO_VISUALIZAR_ERRO_MNI = 'Erro ao realizar login via MNI. Verifique suas credenciais.';

function urlVisualizarProcessoErroMni(Tribunal $tribunal, array $credenciais = []): string
{
    return '/api/processo/visualizar?' . http_build_query(array_merge([
        'tribunal_id' => $tribunal->id,
        'numero_processo' => NUMERO_PROCESSO_VISUALIZAR_ERRO_MNI,
    ], $credenciais));
}

function semSucessoVisualizarProcessoErroMni(string $mensagem): Closure
{
    return fn () => (object) ['sucesso' => false, 'mensagem' => $mensagem];
}

it('visualizar processo ausente com par do cliente responde 500 com error, line e file quando o MNI falha sem regra de padronização', function () {
    $tribunal = Tribunal::factory()->create();
    $simulador = simularIntegracaoMni(semSucessoVisualizarProcessoErroMni('MNI indisponível'));

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson(urlVisualizarProcessoErroMni($tribunal, ['login_pje' => 'login-cliente', 'senha_pje' => 'senha-cliente']))
        ->assertStatus(500)
        ->assertJsonStructure(['error', 'line', 'file'])
        ->assertJsonPath('error', 'MNI indisponível')
        ->assertJsonMissingPath('codigo');

    expect($simulador->requisicoes)->toHaveCount(1)
        ->and($simulador->requisicoes[0]['parametros']['idConsultante'])->toBe('login-cliente')
        ->and($simulador->requisicoes[0]['parametros']['senhaConsultante'])->toBe('senha-cliente');
});

it('visualizar processo ausente com o par padrão recusado responde 500 com a mensagem de login padronizada', function () {
    definirCredenciaisPadrao('env-login', 'env-senha');
    $tribunal = Tribunal::factory()->create();
    $simulador = simularIntegracaoMni(semSucessoVisualizarProcessoErroMni(LOGIN_RECUSADO_VISUALIZAR_ERRO_MNI));

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson(urlVisualizarProcessoErroMni($tribunal))
        ->assertStatus(500)
        ->assertJsonStructure(['error', 'line', 'file'])
        ->assertJsonPath('error', LOGIN_PADRONIZADO_VISUALIZAR_ERRO_MNI)
        ->assertJsonMissingPath('codigo');

    expect($simulador->requisicoes)->toHaveCount(1)
        ->and($simulador->requisicoes[0]['parametros']['idConsultante'])->toBe('env-login')
        ->and($simulador->requisicoes[0]['parametros']['senhaConsultante'])->toBe('env-senha');
});

it('visualizar processo ausente responde 500 com o texto do tribunal quando a mensagem foge do padrão de processo não encontrado', function (string $mensagem) {
    $tribunal = Tribunal::factory()->create();
    $simulador = simularIntegracaoMni(semSucessoVisualizarProcessoErroMni($mensagem));

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson(urlVisualizarProcessoErroMni($tribunal, ['login_pje' => 'login-cliente', 'senha_pje' => 'senha-cliente']))
        ->assertStatus(500)
        ->assertJsonStructure(['error', 'line', 'file'])
        ->assertJsonPath('error', $mensagem)
        ->assertJsonMissingPath('codigo');

    expect($simulador->requisicoes)->toHaveCount(1);
})->with([
    'sem permissão de acesso' => 'Processo não encontrado ou sem permissão de acesso',
    'sem o número' => 'Processo não encontrado',
    'sem a exclamação' => 'Processo de número 99999901120268030001 não encontrado',
    'com prefixo' => 'Erro: Processo de número 99999901120268030001 não encontrado!',
]);

it('visualizar processo ausente com par do cliente e recusa sem loginFailed responde 500 com a mensagem de login padronizada', function () {
    $tribunal = Tribunal::factory()->create();
    $simulador = simularIntegracaoMni(semSucessoVisualizarProcessoErroMni('Erro ao realizar login via MNI.'));

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson(urlVisualizarProcessoErroMni($tribunal, ['login_pje' => 'login-cliente', 'senha_pje' => 'senha-cliente']))
        ->assertStatus(500)
        ->assertJsonStructure(['error', 'line', 'file'])
        ->assertJsonPath('error', LOGIN_PADRONIZADO_VISUALIZAR_ERRO_MNI)
        ->assertJsonMissingPath('codigo');

    expect($simulador->requisicoes)->toHaveCount(1);
});

it('visualizar processo ausente com senha do tribunal indecifrável responde 422 sem chamar o MNI', function () {
    // sem par na requisição e sem par padrão: a consulta usa a credencial do tribunal
    $tribunal = Tribunal::factory()->create(['password' => 'senha-sem-cifra']);
    $simulador = simularIntegracaoMni(semSucessoVisualizarProcessoErroMni(LOGIN_RECUSADO_VISUALIZAR_ERRO_MNI));

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson(urlVisualizarProcessoErroMni($tribunal))
        ->assertStatus(422)
        ->assertJsonStructure(['error', 'line', 'file'])
        ->assertJsonPath('error', 'Credenciais MNI/PJ-e tribunal inválidas.')
        ->assertJsonMissingPath('codigo');

    expect($simulador->requisicoes)->toBe([]);
});

it('visualizar processo no banco responde 200 e agenda a atualização com o par do cliente sem consultar o MNI', function () {
    Queue::fake();
    $tribunal = Tribunal::factory()->create();
    criarProcessoParaConsulta(NUMERO_PROCESSO_VISUALIZAR_ERRO_MNI, $tribunal->id);
    // recusaria o par se fosse chamado: prova que a resposta sai do banco
    $simulador = simularIntegracaoMni(semSucessoVisualizarProcessoErroMni(LOGIN_RECUSADO_VISUALIZAR_ERRO_MNI));

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson(urlVisualizarProcessoErroMni($tribunal, ['login_pje' => 'login-cliente', 'senha_pje' => 'senha-cliente']))
        ->assertOk()
        ->assertJsonPath('numero_processo', NUMERO_PROCESSO_VISUALIZAR_ERRO_MNI)
        ->assertJsonPath('tribunal.id', $tribunal->id)
        ->assertJsonMissingPath('codigo');

    Queue::assertPushed(
        BaixarProcessoMNIJob::class,
        fn ($job) => $job->tribunal->is($tribunal)
            && $job->numero_processo === NUMERO_PROCESSO_VISUALIZAR_ERRO_MNI
            && $job->login_pje === 'login-cliente'
            && $job->senha_pje === 'senha-cliente'
    );
    expect($simulador->requisicoes)->toBe([]);
});

it('visualizar processo ausente com par do cliente responde 500 com o texto do SoapFault', function () {
    $tribunal = Tribunal::factory()->create();
    $simulador = simularIntegracaoMni(fn () => throw new SoapFault('Server', 'Could not connect to host'));

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson(urlVisualizarProcessoErroMni($tribunal, ['login_pje' => 'login-cliente', 'senha_pje' => 'senha-cliente']))
        ->assertStatus(500)
        ->assertJsonStructure(['error', 'line', 'file'])
        ->assertJsonPath('error', 'Could not connect to host')
        ->assertJsonMissingPath('codigo');

    expect($simulador->requisicoes)->toHaveCount(1);
});

it('visualizar processo ausente com a credencial do tribunal recusada responde 500 com a mensagem de login padronizada', function () {
    // sem par na requisição e sem par padrão: a consulta usa a credencial do tribunal
    $tribunal = Tribunal::factory()->create([
        'login' => 'login-do-tribunal',
        'password' => Crypt::encrypt('{SENHA}'),
    ]);
    $simulador = simularIntegracaoMni(semSucessoVisualizarProcessoErroMni(LOGIN_RECUSADO_VISUALIZAR_ERRO_MNI));

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson(urlVisualizarProcessoErroMni($tribunal))
        ->assertStatus(500)
        ->assertJsonStructure(['error', 'line', 'file'])
        ->assertJsonPath('error', LOGIN_PADRONIZADO_VISUALIZAR_ERRO_MNI)
        ->assertJsonMissingPath('codigo');

    expect($simulador->requisicoes)->toHaveCount(1)
        ->and($simulador->requisicoes[0]['parametros']['idConsultante'])->toBe('login-do-tribunal')
        ->and($simulador->requisicoes[0]['parametros']['senhaConsultante'])->toBe('{SENHA}');
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
