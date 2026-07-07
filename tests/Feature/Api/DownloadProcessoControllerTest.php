<?php

use App\Jobs\GerarPdfExportacaoJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\ProcessoExportacao;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;

uses(DatabaseTransactions::class);

beforeEach(function () {
    config()->set('services.api.token', 'tk-test');
});

function payloadValidoExportacao(array $overrides = []): array
{
    return array_merge([
        'numero_processo' => '6001255-81.2024.8.03.0003',
        'tribunal_id' => 1,
        'user_id' => 42,
        'titulo' => 'Processo X — PDF',
        'formato' => 'pdf',
        'ids_selecionados' => [1],
    ], $overrides);
}

function criarProcessoComDocParaController(string $numero, int $idDoc, string $mimetype = 'application/pdf'): void
{
    $numeroCleaned = cleanNumeroProcesso($numero);
    $processo = Processo::create([
        'numero_processo' => $numeroCleaned,
        'tribunal_id' => 1,
        'valor_causa' => '0.00',
    ]);
    ProcessoDocumento::create([
        'processo_id' => $processo->id,
        'id_documento' => $idDoc,
        'mimetype' => $mimetype,
        'data_hora' => '2024-12-12 10:00:00',
        'tipo_documento' => '0',
        'descricao' => 'documento teste',
    ]);
}

it('payload válido retorna 200 com exportacao_id, cria registro e despacha job', function () {
    Queue::fake();

    criarProcessoComDocParaController('6001255-81.2024.8.03.0003', 1);

    $response = $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->postJson('/api/processo/download', payloadValidoExportacao());

    $response->assertOk()
        ->assertJsonStructure(['message', 'exportacao_id']);

    $exportacao = ProcessoExportacao::find($response->json('exportacao_id'));
    expect($exportacao)->not->toBeNull();
    expect($exportacao->status)->toBe(ProcessoExportacao::STATUS_ENFILEIRADO);
    expect($exportacao->user_id)->toBe(42);
    expect($exportacao->titulo)->toBe('Processo X — PDF');

    Queue::assertPushed(GerarPdfExportacaoJob::class, fn ($j) => $j->exportacaoId === $exportacao->id);
});

it('retorna 422 quando user_id ausente', function () {
    Queue::fake();

    $payload = payloadValidoExportacao();
    unset($payload['user_id']);

    $response = $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->postJson('/api/processo/download', $payload);

    $response->assertStatus(422);
    Queue::assertNothingPushed();
});

it('retorna 422 quando formato é inválido', function () {
    Queue::fake();

    $response = $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->postJson('/api/processo/download', payloadValidoExportacao(['formato' => 'docx']));

    $response->assertStatus(422);
    Queue::assertNothingPushed();
});

it('ignora campos extras como email e notificacao_id', function () {
    Queue::fake();

    criarProcessoComDocParaController('6001255-81.2024.8.03.0003', 1);

    $response = $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->postJson('/api/processo/download', payloadValidoExportacao([
            'email' => 'foo@example.com',
            'notificacao_id' => 'abc-123',
        ]));

    $response->assertOk();
});

it('retorna 404 quando nenhum documento disponível para os filtros', function () {
    Queue::fake();

    criarProcessoComDocParaController('6001255-81.2024.8.03.0003', 1);

    $response = $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->postJson('/api/processo/download', payloadValidoExportacao([
            'ids_selecionados' => [999],
        ]));

    $response->assertStatus(404);
    expect(ProcessoExportacao::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('retorna 401 com token inválido', function () {
    Queue::fake();

    $response = $this->withHeaders(['X-API-Token' => 'wrong'])
        ->postJson('/api/processo/download', payloadValidoExportacao());

    $response->assertStatus(401);
});
