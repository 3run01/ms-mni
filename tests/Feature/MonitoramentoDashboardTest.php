<?php

use App\Models\ApiToken;
use App\Models\Processo;
use App\Models\ProcessoMonitoramento;
use App\Models\ProcessoMonitoramentoExecucao;
use App\Models\Tribunal;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;

uses(DatabaseTransactions::class);

// o teste não depende dos assets buildados: sem isso, a renderização do
// app.blade.php quebra em ambiente sem `npm run build`.
beforeEach(fn () => $this->withoutVite());

function usuarioMonitoramentos(): User
{
    return User::factory()->make(['id' => 1]);
}

function numeroMonitoramento(string $sufixo = '001'): string
{
    return substr('9' . getmypid(), 0, 17) . $sufixo;
}

it('redireciona visitante para o login', function () {
    $this->get('/monitoramentos')->assertRedirect('/login');
});

it('lista os processos monitorados no componente monitoramentos/index', function () {
    $numero = numeroMonitoramento();
    $tribunal = Tribunal::factory()->create(['nome' => 'TRF1 Teste']);
    $token = ApiToken::factory()->create(['name' => 'token-cliente-x']);

    ProcessoMonitoramento::factory()->create([
        'numero_processo' => $numero,
        'tribunal_id' => $tribunal->id,
        'api_token_id' => $token->id,
        'intervalo_horas' => 6,
    ]);

    $this->actingAs(usuarioMonitoramentos())
        ->get('/monitoramentos?busca=' . $numero)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('monitoramentos/index')
            ->has('monitoramentos.data', 1)
            ->where('monitoramentos.data.0.numero_processo', $numero)
            ->where('monitoramentos.data.0.tribunal', 'TRF1 Teste')
            ->where('monitoramentos.data.0.token', 'token-cliente-x')
            ->where('monitoramentos.data.0.status', 'ativo')
            ->where('monitoramentos.data.0.intervalo_horas', 6)
            ->where('monitoramentos.data.0.ultima_execucao', null)
            ->has('monitoramentos.data.0.uuid')
            ->has('resumo.ativos')
        );
});

it('não expõe o callback_token na listagem', function () {
    $numero = numeroMonitoramento('002');
    ProcessoMonitoramento::factory()->create([
        'numero_processo' => $numero,
        'callback_token' => 'segredo-do-cliente',
    ]);

    $resposta = $this->actingAs(usuarioMonitoramentos())->get('/monitoramentos?busca=' . $numero);

    expect($resposta->getContent())->not->toContain('segredo-do-cliente');
});

it('traz o resultado da última execução', function () {
    $numero = numeroMonitoramento('003');
    $monitoramento = ProcessoMonitoramento::factory()->create(['numero_processo' => $numero]);

    ProcessoMonitoramentoExecucao::factory()->create([
        'monitoramento_id' => $monitoramento->id,
        'houve_alteracao' => false,
    ]);
    ProcessoMonitoramentoExecucao::factory()->sucesso()->create([
        'monitoramento_id' => $monitoramento->id,
    ]);

    $this->actingAs(usuarioMonitoramentos())
        ->get('/monitoramentos?busca=' . $numero)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('monitoramentos.data.0.ultima_execucao.status', 'sucesso')
            ->where('monitoramentos.data.0.ultima_execucao.houve_alteracao', true)
            ->where('monitoramentos.data.0.ultima_execucao.movimentos_novos', 2)
            ->where('monitoramentos.data.0.ultima_execucao.documentos_novos', 1)
        );
});

it('liga o monitoramento ao processo baixado, quando existe', function () {
    $numero = numeroMonitoramento('004');
    $tribunal = Tribunal::factory()->create();

    $processo = Processo::factory()->create([
        'numero_processo' => $numero,
        'tribunal_id' => $tribunal->id,
    ]);
    ProcessoMonitoramento::factory()->create([
        'numero_processo' => $numero,
        'tribunal_id' => $tribunal->id,
    ]);

    $this->actingAs(usuarioMonitoramentos())
        ->get('/monitoramentos?busca=' . $numero)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('monitoramentos.data.0.processo_id', $processo->id)
        );
});

it('não liga a processo de outro tribunal com o mesmo número', function () {
    $numero = numeroMonitoramento('005');

    Processo::factory()->create([
        'numero_processo' => $numero,
        'tribunal_id' => Tribunal::factory()->create()->id,
    ]);
    ProcessoMonitoramento::factory()->create([
        'numero_processo' => $numero,
        'tribunal_id' => Tribunal::factory()->create()->id,
    ]);

    $this->actingAs(usuarioMonitoramentos())
        ->get('/monitoramentos?busca=' . $numero)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('monitoramentos.data.0.processo_id', null)
        );
});

it('filtra por status', function () {
    $base = numeroMonitoramento('01');
    $tribunal = Tribunal::factory()->create();

    ProcessoMonitoramento::factory()->create(['numero_processo' => $base . '1', 'tribunal_id' => $tribunal->id]);
    ProcessoMonitoramento::factory()->suspenso()->create(['numero_processo' => $base . '2', 'tribunal_id' => $tribunal->id]);

    $this->actingAs(usuarioMonitoramentos())
        ->get('/monitoramentos?busca=' . $base . '&status=suspenso')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('monitoramentos.data', 1)
            ->where('monitoramentos.data.0.numero_processo', $base . '2')
        );
});

it('filtra por tribunal e por token', function () {
    $base = numeroMonitoramento('02');
    $tribunalA = Tribunal::factory()->create();
    $tribunalB = Tribunal::factory()->create();
    $tokenA = ApiToken::factory()->create();

    ProcessoMonitoramento::factory()->create([
        'numero_processo' => $base . '1', 'tribunal_id' => $tribunalA->id, 'api_token_id' => $tokenA->id,
    ]);
    ProcessoMonitoramento::factory()->create([
        'numero_processo' => $base . '2', 'tribunal_id' => $tribunalB->id,
    ]);

    $this->actingAs(usuarioMonitoramentos())
        ->get("/monitoramentos?busca={$base}&tribunal_id={$tribunalA->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('monitoramentos.data', 1)
            ->where('monitoramentos.data.0.numero_processo', $base . '1')
        );

    $this->actingAs(usuarioMonitoramentos())
        ->get("/monitoramentos?busca={$base}&api_token_id={$tokenA->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('monitoramentos.data', 1)
            ->where('monitoramentos.data.0.numero_processo', $base . '1')
        );
});

it('busca aceita número com máscara', function () {
    $numero = numeroMonitoramento('007');
    ProcessoMonitoramento::factory()->create([
        'numero_processo' => $numero,
        'tribunal_id' => Tribunal::factory()->create()->id,
    ]);

    $comMascara = sprintf(
        '%s-%s.%s.%s.%s.%s',
        substr($numero, 0, 7), substr($numero, 7, 2), substr($numero, 9, 4),
        substr($numero, 13, 1), substr($numero, 14, 2), substr($numero, 16, 4),
    );

    $this->actingAs(usuarioMonitoramentos())
        ->get('/monitoramentos?busca=' . urlencode($comMascara))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('monitoramentos.data', 1)
            ->where('monitoramentos.data.0.numero_processo', $numero)
        );
});

it('não lista monitoramento cancelado (soft deleted)', function () {
    $numero = numeroMonitoramento('006');
    $monitoramento = ProcessoMonitoramento::factory()->create(['numero_processo' => $numero]);

    $monitoramento->update(['status' => ProcessoMonitoramento::STATUS_CANCELADO]);
    $monitoramento->delete();

    $this->actingAs(usuarioMonitoramentos())
        ->get('/monitoramentos?busca=' . $numero)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('monitoramentos.data', 0));
});

it('rejeita status inválido no filtro', function () {
    $this->actingAs(usuarioMonitoramentos())
        ->get('/monitoramentos?status=inexistente')
        ->assertSessionHasErrors('status');
});
