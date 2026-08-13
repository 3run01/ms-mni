<?php

use App\Jobs\ExecutarMonitoramentoProcessoJob;
use App\Models\CredencialPje;
use App\Models\ProcessoMonitoramento;
use App\Models\ProcessoMonitoramentoExecucao;
use App\Models\Tribunal;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->token = criarTokenApi('mni_monitoramento');
    $this->headers = ['X-API-Token' => 'mni_monitoramento'];
    $this->tribunal = Tribunal::factory()->create();
});

function payloadMonitoramento(Tribunal $tribunal, array $extra = []): array
{
    return array_merge([
        'numero_processo' => '0000832-35.2024.4.01.3200',
        'tribunal_id' => $tribunal->id,
        'intervalo_horas' => 6,
        'callback_url' => 'https://example.com/webhook',
        'callback_token' => 'tok-cliente',
    ], $extra);
}

it('exige token em todas as rotas', function () {
    $monitoramento = ProcessoMonitoramento::factory()->create();

    $this->postJson('/api/processo/monitoramentos', [])->assertStatus(401);
    $this->getJson('/api/processo/monitoramentos')->assertStatus(401);
    $this->getJson("/api/processo/monitoramentos/{$monitoramento->uuid}")->assertStatus(401);
    $this->patchJson("/api/processo/monitoramentos/{$monitoramento->uuid}", [])->assertStatus(401);
    $this->deleteJson("/api/processo/monitoramentos/{$monitoramento->uuid}")->assertStatus(401);
    $this->getJson("/api/processo/monitoramentos/{$monitoramento->uuid}/execucoes")->assertStatus(401);
    $this->postJson("/api/processo/monitoramentos/{$monitoramento->uuid}/executar")->assertStatus(401);
});

it('cria monitoramento com credencial cifrada e não devolve segredo', function () {
    $response = $this->withHeaders($this->headers)
        ->postJson('/api/processo/monitoramentos', payloadMonitoramento($this->tribunal, [
            'login_pje' => '12345678900',
            'senha_pje' => 'segredo-pje',
        ]))
        ->assertStatus(201);

    $dados = $response->json('data');

    expect($dados['uuid'])->not->toBeNull()
        ->and($dados['status'])->toBe('ativo')
        ->and($dados['numero_processo'])->toBe('00008323520244013200')
        ->and($dados['intervalo_horas'])->toBe(6)
        ->and($dados['falhas_consecutivas'])->toBe(0)
        ->and($dados['credencial']['login_mascarado'])->toBe('123*****900')
        ->and($dados)->not->toHaveKeys(['callback_token', 'id', 'api_token_id'])
        ->and($dados['credencial'])->not->toHaveKeys(['login', 'senha', 'login_hash']);

    $this->assertDatabaseMissing('credenciais_pje', ['login' => '12345678900']);
    $this->assertDatabaseMissing('credenciais_pje', ['senha' => 'segredo-pje']);

    $monitoramento = ProcessoMonitoramento::where('uuid', $dados['uuid'])->first();
    expect($monitoramento->api_token_id)->toBe($this->token->id)
        ->and($monitoramento->credencial_id)->not->toBeNull()
        ->and($monitoramento->proxima_execucao_em->diffInMinutes(now(), true))->toBeLessThan(2);
});

it('cria sem credencial quando o payload não traz o par', function () {
    $response = $this->withHeaders($this->headers)
        ->postJson('/api/processo/monitoramentos', payloadMonitoramento($this->tribunal))
        ->assertStatus(201);

    expect($response->json('data.credencial'))->toBeNull()
        ->and(CredencialPje::where('api_token_id', $this->token->id)->count())->toBe(0);
});

it('rejeita intervalo fora da faixa e campos ausentes', function () {
    $this->withHeaders($this->headers)
        ->postJson('/api/processo/monitoramentos', payloadMonitoramento($this->tribunal, ['intervalo_horas' => 0]))
        ->assertStatus(422)->assertJsonValidationErrors('intervalo_horas');

    $this->withHeaders($this->headers)
        ->postJson('/api/processo/monitoramentos', payloadMonitoramento($this->tribunal, ['intervalo_horas' => 721]))
        ->assertStatus(422)->assertJsonValidationErrors('intervalo_horas');

    $this->withHeaders($this->headers)
        ->postJson('/api/processo/monitoramentos', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['numero_processo', 'tribunal_id', 'intervalo_horas', 'callback_url', 'callback_token']);
});

it('rejeita callback_url insegura', function () {
    foreach (['http://example.com/webhook', 'https://127.0.0.1/webhook', 'https://192.168.0.10/webhook'] as $url) {
        $this->withHeaders($this->headers)
            ->postJson('/api/processo/monitoramentos', payloadMonitoramento($this->tribunal, ['callback_url' => $url]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('callback_url');
    }
});

it('rejeita tribunal inexistente ou inativo', function () {
    $inativo = Tribunal::factory()->create(['ativo' => false]);

    $this->withHeaders($this->headers)
        ->postJson('/api/processo/monitoramentos', payloadMonitoramento($this->tribunal, ['tribunal_id' => 999999]))
        ->assertStatus(422)->assertJsonValidationErrors('tribunal_id');

    $this->withHeaders($this->headers)
        ->postJson('/api/processo/monitoramentos', payloadMonitoramento($this->tribunal, ['tribunal_id' => $inativo->id]))
        ->assertStatus(422)->assertJsonValidationErrors('tribunal_id');
});

it('rejeita par de credenciais incompleto', function () {
    $this->withHeaders($this->headers)
        ->postJson('/api/processo/monitoramentos', payloadMonitoramento($this->tribunal, ['login_pje' => 'so-login']))
        ->assertStatus(422)->assertJsonValidationErrors('senha_pje');

    $this->withHeaders($this->headers)
        ->postJson('/api/processo/monitoramentos', payloadMonitoramento($this->tribunal, ['senha_pje' => 'so-senha']))
        ->assertStatus(422)->assertJsonValidationErrors('login_pje');
});

it('retorna 409 com o uuid existente ao duplicar e libera após cancelar', function () {
    $primeiro = $this->withHeaders($this->headers)
        ->postJson('/api/processo/monitoramentos', payloadMonitoramento($this->tribunal))
        ->assertStatus(201)->json('data.uuid');

    $this->withHeaders($this->headers)
        ->postJson('/api/processo/monitoramentos', payloadMonitoramento($this->tribunal))
        ->assertStatus(409)
        ->assertJson(['uuid' => $primeiro]);

    $this->withHeaders($this->headers)
        ->deleteJson("/api/processo/monitoramentos/{$primeiro}")
        ->assertStatus(204);

    $this->withHeaders($this->headers)
        ->postJson('/api/processo/monitoramentos', payloadMonitoramento($this->tribunal))
        ->assertStatus(201);
});

it('respeita o limite de monitoramentos ativos por token', function () {
    config()->set('pje.monitoramento.max_ativos_por_token', 1);

    $this->withHeaders($this->headers)
        ->postJson('/api/processo/monitoramentos', payloadMonitoramento($this->tribunal))
        ->assertStatus(201);

    $this->withHeaders($this->headers)
        ->postJson('/api/processo/monitoramentos', payloadMonitoramento($this->tribunal, [
            'numero_processo' => '0000833-35.2024.4.01.3200',
        ]))
        ->assertStatus(422)
        ->assertJson(['error' => 'Limite de monitoramentos ativos por token atingido']);
});

it('lista apenas os monitoramentos do token, com filtro de status', function () {
    $meu = ProcessoMonitoramento::factory()->create(['api_token_id' => $this->token->id]);
    $meuPausado = ProcessoMonitoramento::factory()->pausado()->create(['api_token_id' => $this->token->id]);
    $deOutro = ProcessoMonitoramento::factory()->create();

    $uuids = collect($this->withHeaders($this->headers)
        ->getJson('/api/processo/monitoramentos')
        ->assertOk()
        ->json('data'))->pluck('uuid');

    expect($uuids)->toContain($meu->uuid)->toContain($meuPausado->uuid)->not->toContain($deOutro->uuid);

    $filtrados = collect($this->withHeaders($this->headers)
        ->getJson('/api/processo/monitoramentos?status=pausado')
        ->assertOk()
        ->json('data'))->pluck('uuid');

    expect($filtrados)->toContain($meuPausado->uuid)->not->toContain($meu->uuid);
});

it('mostra detalhe com as últimas execuções', function () {
    $monitoramento = ProcessoMonitoramento::factory()->create(['api_token_id' => $this->token->id]);
    ProcessoMonitoramentoExecucao::factory()->sucesso()->create(['monitoramento_id' => $monitoramento->id]);

    $dados = $this->withHeaders($this->headers)
        ->getJson("/api/processo/monitoramentos/{$monitoramento->uuid}")
        ->assertOk()
        ->json('data');

    expect($dados['uuid'])->toBe($monitoramento->uuid)
        ->and($dados['execucoes'])->toHaveCount(1)
        ->and($dados['execucoes'][0]['movimentos_novos'])->toBe(2);
});

it('devolve 404 para uuid de outro token ou inexistente', function () {
    $deOutro = ProcessoMonitoramento::factory()->create();

    $this->withHeaders($this->headers)
        ->getJson("/api/processo/monitoramentos/{$deOutro->uuid}")
        ->assertStatus(404);

    $this->withHeaders($this->headers)
        ->getJson('/api/processo/monitoramentos/' . \Illuminate\Support\Str::uuid())
        ->assertStatus(404);
});

it('atualiza intervalo e callback', function () {
    $monitoramento = ProcessoMonitoramento::factory()->create(['api_token_id' => $this->token->id]);

    $this->withHeaders($this->headers)
        ->patchJson("/api/processo/monitoramentos/{$monitoramento->uuid}", [
            'intervalo_horas' => 12,
            'callback_url' => 'https://example.com/outro-webhook',
            'callback_token' => 'tok-novo',
        ])
        ->assertOk()
        ->assertJsonPath('data.intervalo_horas', 12)
        ->assertJsonPath('data.callback_url', 'https://example.com/outro-webhook');

    expect($monitoramento->fresh()->callback_token)->toBe('tok-novo');
});

it('pausa e retoma zerando falhas', function () {
    $monitoramento = ProcessoMonitoramento::factory()->suspenso()->create([
        'api_token_id' => $this->token->id,
        'falhas_consecutivas' => 5,
        'proxima_execucao_em' => now()->addDays(3),
    ]);

    $this->withHeaders($this->headers)
        ->patchJson("/api/processo/monitoramentos/{$monitoramento->uuid}", ['status' => 'ativo'])
        ->assertOk()
        ->assertJsonPath('data.status', 'ativo')
        ->assertJsonPath('data.falhas_consecutivas', 0);

    expect($monitoramento->fresh()->proxima_execucao_em->diffInMinutes(now(), true))->toBeLessThan(2);

    $this->withHeaders($this->headers)
        ->patchJson("/api/processo/monitoramentos/{$monitoramento->uuid}", ['status' => 'pausado'])
        ->assertOk()
        ->assertJsonPath('data.status', 'pausado');
});

it('não aceita cancelar via PATCH', function () {
    $monitoramento = ProcessoMonitoramento::factory()->create(['api_token_id' => $this->token->id]);

    $this->withHeaders($this->headers)
        ->patchJson("/api/processo/monitoramentos/{$monitoramento->uuid}", ['status' => 'cancelado'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

it('cancela com soft delete e some das consultas', function () {
    $monitoramento = ProcessoMonitoramento::factory()->create(['api_token_id' => $this->token->id]);

    $this->withHeaders($this->headers)
        ->deleteJson("/api/processo/monitoramentos/{$monitoramento->uuid}")
        ->assertStatus(204);

    expect($monitoramento->fresh()->status)->toBe(ProcessoMonitoramento::STATUS_CANCELADO)
        ->and($monitoramento->fresh()->trashed())->toBeTrue();

    $this->withHeaders($this->headers)
        ->getJson("/api/processo/monitoramentos/{$monitoramento->uuid}")
        ->assertStatus(404);
});

it('executa sob demanda sem mexer no agendamento', function () {
    Queue::fake();

    $monitoramento = ProcessoMonitoramento::factory()->create([
        'api_token_id' => $this->token->id,
        'proxima_execucao_em' => now()->addHours(5),
    ]);
    $agendamento = $monitoramento->proxima_execucao_em;

    $this->withHeaders($this->headers)
        ->postJson("/api/processo/monitoramentos/{$monitoramento->uuid}/executar")
        ->assertStatus(202)
        ->assertJson(['uuid' => $monitoramento->uuid]);

    Queue::assertPushed(ExecutarMonitoramentoProcessoJob::class, fn ($job) => $job->monitoramentoId === $monitoramento->id
        && $job->queue === 'monitoramento');

    expect($monitoramento->fresh()->proxima_execucao_em->eq($agendamento))->toBeTrue();
});

it('lista o histórico de execuções isolado por token', function () {
    $monitoramento = ProcessoMonitoramento::factory()->create(['api_token_id' => $this->token->id]);
    ProcessoMonitoramentoExecucao::factory()->count(3)->create(['monitoramento_id' => $monitoramento->id]);

    $deOutro = ProcessoMonitoramento::factory()->create();
    ProcessoMonitoramentoExecucao::factory()->create(['monitoramento_id' => $deOutro->id]);

    $this->withHeaders($this->headers)
        ->getJson("/api/processo/monitoramentos/{$monitoramento->uuid}/execucoes")
        ->assertOk()
        ->assertJsonCount(3, 'data');

    $this->withHeaders($this->headers)
        ->getJson("/api/processo/monitoramentos/{$deOutro->uuid}/execucoes")
        ->assertStatus(404);
});
