<?php

use App\Jobs\BaixarProcessoMNIJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\Tribunal;
use App\Services\Processo\JanelaAtualizacaoProcessoService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/*
 * Janela de 10 min no GET /processo/visualizar (T04; RF-15, RF-16, CT-05,
 * RNF-01, RNF-04). Com o processo no banco, a chamada só enfileira o
 * BaixarProcessoMNIJob quando abre a janela do (tribunal_id, numero_processo).
 * Com o processo fora do banco, a consulta síncrona bem-sucedida abre a janela
 * sem job (Q-03). /processo/consultar e o expediente não passam pela janela
 * (RF-16, Q-02).
 */

uses(DatabaseTransactions::class);

const NUMERO_JANELA_VISUALIZAR = '9999807-12.2026.8.03.0001';
const NUMERO_LIMPO_JANELA_VISUALIZAR = '99998071220268030001';
const LOGIN_A_JANELA_VISUALIZAR = 'login-par-a-janela-5e1';
const SENHA_A_JANELA_VISUALIZAR = 'senha-par-a-janela-8f3';
const LOGIN_B_JANELA_VISUALIZAR = 'login-par-b-janela-2c7';
const SENHA_B_JANELA_VISUALIZAR = 'senha-par-b-janela-6d4';

beforeEach(function () {
    criarTokenApi();
    // isola do .env da máquina: cada chamada leva o seu par
    definirCredenciaisPadrao(null, null);
});

function parAJanelaVisualizar(): array
{
    return ['login_pje' => LOGIN_A_JANELA_VISUALIZAR, 'senha_pje' => SENHA_A_JANELA_VISUALIZAR];
}

function parBJanelaVisualizar(): array
{
    return ['login_pje' => LOGIN_B_JANELA_VISUALIZAR, 'senha_pje' => SENHA_B_JANELA_VISUALIZAR];
}

/**
 * Processo no banco, numa linha nova, com 1 documento: a resposta tem um
 * item em `documentos` para comparar as chaves entre as chamadas.
 */
function processoNoBancoJanelaVisualizar(?Tribunal $tribunal = null): Processo
{
    $tribunal ??= Tribunal::factory()->create();

    $processo = Processo::factory()->create([
        'tribunal_id' => $tribunal->id,
        'numero_processo' => NUMERO_LIMPO_JANELA_VISUALIZAR,
    ]);

    ProcessoDocumento::create([
        'processo_id' => $processo->id,
        'id_documento' => 9807001,
        'tipo_documento' => '57',
        'data_hora' => '2026-03-06 10:00:00',
        'descricao' => 'Documento da janela',
        'mimetype' => 'application/pdf',
        'status' => ProcessoDocumento::STATUS_PENDENTE,
        'tentativas_download' => 0,
    ]);

    return $processo;
}

function visualizarJanela(int $tribunalId, array $par = []): TestResponse
{
    return test()->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/visualizar?' . http_build_query(array_merge([
            'tribunal_id' => $tribunalId,
            'numero_processo' => NUMERO_JANELA_VISUALIZAR,
        ], $par)));
}

function consultarJanela(int $tribunalId, array $par = []): TestResponse
{
    return test()->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar?' . http_build_query(array_merge([
            'tribunal_id' => $tribunalId,
            'numero_processo' => NUMERO_JANELA_VISUALIZAR,
        ], $par)));
}

/**
 * MNI simulado da consulta síncrona: devolve o processo com o número limpo,
 * sem partes, movimentos nem documentos (nada vai à fila de download).
 */
function mniComSucessoJanelaVisualizar(): stdClass
{
    return simularIntegracaoMni(fn () => (object) [
        'sucesso' => true,
        'processo' => (object) [
            'dadosBasicos' => (object) [
                'numero' => NUMERO_LIMPO_JANELA_VISUALIZAR,
                'codigoLocalidade' => '1',
                'classeProcessual' => '7',
                'nivelSigilo' => 0,
                'valorCausa' => 0,
                'polo' => [],
            ],
            'movimento' => [],
            'documento' => [],
        ],
    ]);
}

function consultasSincronasJanelaVisualizar(stdClass $simulador): int
{
    return collect($simulador->requisicoes)
        ->where('operacao', 'consultarProcesso')
        ->count();
}

/**
 * Os BaixarProcessoMNIJob enfileirados, na ordem do despacho.
 */
function jobsDeAtualizacaoJanelaVisualizar(): \Illuminate\Support\Collection
{
    return Queue::pushed(BaixarProcessoMNIJob::class)->values();
}

function jobLevaOParJanelaVisualizar(BaixarProcessoMNIJob $job, array $par): bool
{
    return $job->login_pje === $par['login_pje'] && $job->senha_pje === $par['senha_pje'];
}

/**
 * As chaves do corpo e do 1º documento: CT-05 pede o mesmo corpo com ou sem
 * despacho.
 *
 * @return array{0: array<int, string>, 1: array<int, string>}
 */
function chavesDaRespostaJanelaVisualizar(TestResponse $response): array
{
    $corpo = $response->json();

    return [array_keys($corpo), array_keys($corpo['documentos'][0] ?? [])];
}

// ---------- RF-15: a janela no processo do banco ----------

it('enfileira 1 job em t0 com o par A e nenhum outro até a janela vencer, com qualquer par', function () {
    Queue::fake();
    $processo = processoNoBancoJanelaVisualizar();

    visualizarJanela($processo->tribunal_id, parAJanelaVisualizar())->assertOk();

    expect(jobsDeAtualizacaoJanelaVisualizar())->toHaveCount(1);

    $this->travel(1)->minutes();
    visualizarJanela($processo->tribunal_id, parBJanelaVisualizar())->assertOk();

    // t0+1 com o par B: continua 1 job, e o único leva o par A
    $jobs = jobsDeAtualizacaoJanelaVisualizar();
    expect($jobs)->toHaveCount(1)
        ->and(jobLevaOParJanelaVisualizar($jobs->sole(), parAJanelaVisualizar()))->toBeTrue()
        ->and($jobs->sole()->tribunal->id)->toBe($processo->tribunal_id)
        ->and($jobs->sole()->numero_processo)->toBe(NUMERO_LIMPO_JANELA_VISUALIZAR);

    $this->travel(8)->minutes();
    visualizarJanela($processo->tribunal_id, parBJanelaVisualizar())->assertOk();
    visualizarJanela($processo->tribunal_id, parAJanelaVisualizar())->assertOk();
    visualizarJanela($processo->tribunal_id)->assertOk();

    // t0+9: nenhuma chamada enfileira job novo
    expect(jobsDeAtualizacaoJanelaVisualizar())->toHaveCount(1);

    $this->travel(2)->minutes();
    visualizarJanela($processo->tribunal_id, parBJanelaVisualizar())->assertOk();

    // t0+11 com o par B: a janela venceu, e o 2º job leva o par B
    $jobs = jobsDeAtualizacaoJanelaVisualizar();
    expect($jobs)->toHaveCount(2)
        ->and(jobLevaOParJanelaVisualizar($jobs[0], parAJanelaVisualizar()))->toBeTrue()
        ->and(jobLevaOParJanelaVisualizar($jobs[1], parBJanelaVisualizar()))->toBeTrue();
});

it('as chamadas suprimidas não estendem a janela', function () {
    Queue::fake();
    $processo = processoNoBancoJanelaVisualizar();

    visualizarJanela($processo->tribunal_id, parAJanelaVisualizar())->assertOk();

    // chamadas suprimidas a cada 3 min até t0+9
    foreach ([3, 3, 3] as $minutos) {
        $this->travel($minutos)->minutes();
        visualizarJanela($processo->tribunal_id, parBJanelaVisualizar())->assertOk();
    }

    expect(jobsDeAtualizacaoJanelaVisualizar())->toHaveCount(1);

    // t0+10min01s: a janela aberta em t0 venceu, apesar das chamadas de t0+3/6/9
    $this->travel(61)->seconds();
    visualizarJanela($processo->tribunal_id, parBJanelaVisualizar())->assertOk();

    expect(jobsDeAtualizacaoJanelaVisualizar())->toHaveCount(2);
});

it('o mesmo número em outra linha enfileira o seu job em t0+1', function () {
    Queue::fake();
    $processo = processoNoBancoJanelaVisualizar();
    $outraLinha = processoNoBancoJanelaVisualizar(Tribunal::factory()->create());

    visualizarJanela($processo->tribunal_id, parAJanelaVisualizar())->assertOk();

    $this->travel(1)->minutes();
    visualizarJanela($outraLinha->tribunal_id, parBJanelaVisualizar())->assertOk();

    $jobs = jobsDeAtualizacaoJanelaVisualizar();
    expect($jobs)->toHaveCount(2)
        ->and($jobs[0]->tribunal->id)->toBe($processo->tribunal_id)
        ->and($jobs[1]->tribunal->id)->toBe($outraLinha->tribunal_id)
        ->and($jobs[1]->numero_processo)->toBe(NUMERO_LIMPO_JANELA_VISUALIZAR)
        ->and(jobLevaOParJanelaVisualizar($jobs[1], parBJanelaVisualizar()))->toBeTrue();
});

it('as chamadas de t0, t0+1 e t0+11 respondem 200 com as mesmas chaves no corpo e no documento (CT-05)', function () {
    Queue::fake();
    $processo = processoNoBancoJanelaVisualizar();

    $t0 = visualizarJanela($processo->tribunal_id, parAJanelaVisualizar())->assertOk();

    $this->travel(1)->minutes();
    $t1 = visualizarJanela($processo->tribunal_id, parBJanelaVisualizar())->assertOk();

    $this->travel(10)->minutes();
    $t11 = visualizarJanela($processo->tribunal_id, parBJanelaVisualizar())->assertOk();

    // t0 e t0+11 despacharam, t0+1 não: o corpo não muda com o despacho
    expect(jobsDeAtualizacaoJanelaVisualizar())->toHaveCount(2);

    [$corpoT0, $documentoT0] = chavesDaRespostaJanelaVisualizar($t0);

    expect($documentoT0)->not->toBeEmpty()
        ->and(chavesDaRespostaJanelaVisualizar($t1))->toBe([$corpoT0, $documentoT0])
        ->and(chavesDaRespostaJanelaVisualizar($t11))->toBe([$corpoT0, $documentoT0])
        ->and($t1->json('numero_processo'))->toBe(NUMERO_LIMPO_JANELA_VISUALIZAR)
        ->and($t1->json('documentos.0.id_documento'))->toBe($t0->json('documentos.0.id_documento'));
});

it('a consulta síncrona que falha em t0 não abre a janela, e o processo gravado depois enfileira 1 job em t0+1', function () {
    Queue::fake();
    $tribunal = Tribunal::factory()->create();
    $simulador = simularIntegracaoMni(fn () => (object) ['sucesso' => false, 'mensagem' => 'MNI indisponível']);

    visualizarJanela($tribunal->id, parAJanelaVisualizar())->assertStatus(500);

    expect(consultasSincronasJanelaVisualizar($simulador))->toBe(1)
        ->and(Cache::has(JanelaAtualizacaoProcessoService::chave($tribunal->id, NUMERO_LIMPO_JANELA_VISUALIZAR)))->toBeFalse()
        ->and(jobsDeAtualizacaoJanelaVisualizar())->toHaveCount(0);

    processoNoBancoJanelaVisualizar($tribunal);

    $this->travel(1)->minutes();
    visualizarJanela($tribunal->id, parBJanelaVisualizar())->assertOk();

    $jobs = jobsDeAtualizacaoJanelaVisualizar();
    expect($jobs)->toHaveCount(1)
        ->and(jobLevaOParJanelaVisualizar($jobs->sole(), parBJanelaVisualizar()))->toBeTrue()
        ->and(consultasSincronasJanelaVisualizar($simulador))->toBe(1);
});

// ---------- RF-16: processo fora do banco (Q-03) ----------

it('fora do banco: 1ª chamada 1 consulta e 0 jobs; 2ª 0 e 0; 11 min depois 0 consultas e 1 job', function () {
    Queue::fake();
    $tribunal = Tribunal::factory()->create();
    $simulador = mniComSucessoJanelaVisualizar();

    $primeira = visualizarJanela($tribunal->id, parAJanelaVisualizar())
        ->assertOk()
        ->assertJsonPath('numero_processo', NUMERO_LIMPO_JANELA_VISUALIZAR);

    expect(consultasSincronasJanelaVisualizar($simulador))->toBe(1)
        ->and(jobsDeAtualizacaoJanelaVisualizar())->toHaveCount(0)
        ->and(Processo::where('tribunal_id', $tribunal->id)->where('numero_processo', NUMERO_LIMPO_JANELA_VISUALIZAR)->exists())->toBeTrue();

    // logo em seguida: o processo está no banco e a janela, aberta pela consulta síncrona
    $segunda = visualizarJanela($tribunal->id, parBJanelaVisualizar())->assertOk();

    expect(consultasSincronasJanelaVisualizar($simulador))->toBe(1)
        ->and(jobsDeAtualizacaoJanelaVisualizar())->toHaveCount(0)
        ->and(array_keys($segunda->json()))->toBe(array_keys($primeira->json()));

    $this->travel(11)->minutes();
    visualizarJanela($tribunal->id, parBJanelaVisualizar())->assertOk();

    $jobs = jobsDeAtualizacaoJanelaVisualizar();
    expect(consultasSincronasJanelaVisualizar($simulador))->toBe(1)
        ->and($jobs)->toHaveCount(1)
        ->and(jobLevaOParJanelaVisualizar($jobs->sole(), parBJanelaVisualizar()))->toBeTrue();
});

// ---------- RF-16: os outros despachos não passam pela janela (Q-02) ----------

it('/processo/consultar enfileira 1 job por chamada, com ou sem a janela aberta pelo visualizar', function () {
    Queue::fake();
    $processo = processoNoBancoJanelaVisualizar();

    consultarJanela($processo->tribunal_id, parAJanelaVisualizar())->assertOk();
    consultarJanela($processo->tribunal_id, parBJanelaVisualizar())->assertOk();

    expect(jobsDeAtualizacaoJanelaVisualizar())->toHaveCount(2);

    // /processo/consultar não abriu a janela: o visualizar 1 min depois enfileira o seu
    $this->travel(1)->minutes();
    visualizarJanela($processo->tribunal_id, parAJanelaVisualizar())->assertOk();

    expect(jobsDeAtualizacaoJanelaVisualizar())->toHaveCount(3)
        ->and(jobLevaOParJanelaVisualizar(jobsDeAtualizacaoJanelaVisualizar()[2], parAJanelaVisualizar()))->toBeTrue();

    // com a janela aberta, /processo/consultar continua enfileirando
    consultarJanela($processo->tribunal_id, parBJanelaVisualizar())->assertOk();

    expect(jobsDeAtualizacaoJanelaVisualizar())->toHaveCount(4)
        ->and(jobLevaOParJanelaVisualizar(jobsDeAtualizacaoJanelaVisualizar()[3], parBJanelaVisualizar()))->toBeTrue();
});

it('com a janela aberta pelo visualizar, o despacho do expediente entra na fila', function () {
    Queue::fake();
    $processo = processoNoBancoJanelaVisualizar();
    $tribunal = Tribunal::find($processo->tribunal_id);

    visualizarJanela($processo->tribunal_id, parAJanelaVisualizar())->assertOk();

    expect(jobsDeAtualizacaoJanelaVisualizar())->toHaveCount(1);

    // o mesmo despacho de ConsultarExpedienteService.php:48
    BaixarProcessoMNIJob::dispatch($tribunal, NUMERO_LIMPO_JANELA_VISUALIZAR);

    $jobs = jobsDeAtualizacaoJanelaVisualizar();
    expect($jobs)->toHaveCount(2)
        ->and($jobs[1]->login_pje)->toBeNull()
        ->and($jobs[1]->senha_pje)->toBeNull();
});

it('o serviço do expediente e o job não citam a janela, e o job não tem trava de unicidade', function () {
    $expediente = file_get_contents(app_path('Services/MNI/Intercomunicacao/ConsultarExpedienteService.php'));
    $job = file_get_contents(app_path('Jobs/BaixarProcessoMNIJob.php'));

    foreach ([$expediente, $job] as $codigo) {
        expect($codigo)->not->toContain('JanelaAtualizacaoProcessoService')
            ->and($codigo)->not->toContain('baixar-processo:');
    }

    $interfaces = class_implements(BaixarProcessoMNIJob::class);

    expect($interfaces)->not->toHaveKey(ShouldBeUnique::class)
        ->and($interfaces)->toHaveKey(ShouldBeEncrypted::class);
});

it('com um job do expediente na fila e sem janela, o visualizar enfileira o seu job com o seu par', function () {
    Queue::fake();
    $processo = processoNoBancoJanelaVisualizar();

    BaixarProcessoMNIJob::dispatch(Tribunal::find($processo->tribunal_id), NUMERO_LIMPO_JANELA_VISUALIZAR);

    expect(jobsDeAtualizacaoJanelaVisualizar())->toHaveCount(1);

    visualizarJanela($processo->tribunal_id, parAJanelaVisualizar())->assertOk();

    $jobs = jobsDeAtualizacaoJanelaVisualizar();
    expect($jobs)->toHaveCount(2)
        ->and(jobLevaOParJanelaVisualizar($jobs[1], parAJanelaVisualizar()))->toBeTrue()
        ->and($jobs[1]->tribunal->id)->toBe($processo->tribunal_id)
        ->and($jobs[1]->numero_processo)->toBe(NUMERO_LIMPO_JANELA_VISUALIZAR);
});

// ---------- RNF-01: o par B não vai à fila, ao cache nem ao log ----------

it('com fila e cache no banco, o par B não aparece em jobs.payload, nas linhas de cache nem nos logs', function () {
    // fila e cache reais no banco, dentro da transação do teste
    config([
        'queue.default' => 'database',
        'cache.default' => 'database',
    ]);

    $logs = [];
    Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$logs) {
        $logs[] = $log->message . ' ' . json_encode($log->context);
    });

    $processo = processoNoBancoJanelaVisualizar();
    $ultimoJobAntes = (int) DB::table('jobs')->max('id');

    visualizarJanela($processo->tribunal_id, parAJanelaVisualizar())->assertOk();

    $this->travel(1)->minutes();
    visualizarJanela($processo->tribunal_id, parBJanelaVisualizar())->assertOk();

    $this->travel(10)->minutes();
    visualizarJanela($processo->tribunal_id, parBJanelaVisualizar())->assertOk();

    $proibidos = [
        LOGIN_A_JANELA_VISUALIZAR,
        SENHA_A_JANELA_VISUALIZAR,
        LOGIN_B_JANELA_VISUALIZAR,
        SENHA_B_JANELA_VISUALIZAR,
    ];

    // jobs: t0 (A) e t0+11 (B), cifrados; o de t0+1 (B) foi suprimido
    $jobs = DB::table('jobs')->where('id', '>', $ultimoJobAntes)->orderBy('id')->get();
    expect($jobs)->toHaveCount(2);

    foreach ($jobs as $job) {
        foreach ($proibidos as $proibido) {
            expect($job->payload)->not->toContain($proibido);
        }
    }

    // o payload decifrado prova que os 2 jobs são os de A e de B
    $comandos = $jobs->map(fn ($job) => Crypt::decrypt(json_decode($job->payload, true)['data']['command']));
    expect($comandos[0])->toContain(LOGIN_A_JANELA_VISUALIZAR)
        ->and($comandos[1])->toContain(LOGIN_B_JANELA_VISUALIZAR)
        ->and($comandos[1])->toContain(SENHA_B_JANELA_VISUALIZAR);

    // cache: a janela está gravada, e nenhuma linha leva o par
    $tabelaCache = config('cache.stores.database.table');
    expect(DB::table($tabelaCache)
        ->where('key', 'like', '%' . JanelaAtualizacaoProcessoService::chave($processo->tribunal_id, NUMERO_LIMPO_JANELA_VISUALIZAR))
        ->exists())->toBeTrue();

    $linhasCache = DB::table($tabelaCache)->get()
        ->map(fn ($linha) => $linha->key . ' ' . $linha->value)
        ->implode("\n");

    foreach ($proibidos as $proibido) {
        expect($linhasCache)->not->toContain($proibido)
            ->and(implode("\n", $logs))->not->toContain($proibido);
    }
});
