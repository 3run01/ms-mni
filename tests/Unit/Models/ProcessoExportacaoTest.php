<?php

use App\Models\ProcessoExportacao;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('persiste e recupera registro com casts corretos', function () {
    $exportacao = ProcessoExportacao::factory()->create([
        'filtros' => ['ids_selecionados' => [10, 20]],
    ]);

    $reload = ProcessoExportacao::find($exportacao->id);

    expect($reload->filtros)->toBe(['ids_selecionados' => [10, 20]]);
    expect($reload->status)->toBe(ProcessoExportacao::STATUS_ENFILEIRADO);
    expect($reload->webhook_tentativas)->toBe(0);
});

it('retorna jaFoiNotificado() falso quando webhook_enviado_em nulo', function () {
    $exportacao = ProcessoExportacao::factory()->create();

    expect($exportacao->jaFoiNotificado())->toBeFalse();
});

it('retorna jaFoiNotificado() verdadeiro quando webhook_enviado_em preenchido', function () {
    $exportacao = ProcessoExportacao::factory()->webhookEnviado()->create();

    expect($exportacao->jaFoiNotificado())->toBeTrue();
});

it('factory concluido() popula s3_path com pattern downloads/{user_id}/{uuid}.pdf', function () {
    $exportacao = ProcessoExportacao::factory()->concluido()->create(['user_id' => 42]);

    expect($exportacao->s3_path)->toMatch('#^downloads/42/[0-9a-f-]+\.pdf$#');
    expect($exportacao->status)->toBe(ProcessoExportacao::STATUS_CONCLUIDO);
});

it('factory processando() seta status processando e gera uuid_arquivo', function () {
    $exportacao = ProcessoExportacao::factory()->processando()->create();

    expect($exportacao->status)->toBe(ProcessoExportacao::STATUS_PROCESSANDO);
    expect($exportacao->uuid_arquivo)->not->toBeNull();
});

it('factory falhou() seta status falhou e erro_resumo informado', function () {
    $exportacao = ProcessoExportacao::factory()->falhou('teste de erro')->create();

    expect($exportacao->status)->toBe(ProcessoExportacao::STATUS_FALHOU);
    expect($exportacao->erro_resumo)->toBe('teste de erro');
});

it('persiste callback_url e callback_token', function () {
    $e = \App\Models\ProcessoExportacao::factory()->create([
        'callback_url' => 'https://cliente.exemplo.gov.br/webhook',
        'callback_token' => 'segredo-123',
    ]);

    expect($e->fresh()->callback_url)->toBe('https://cliente.exemplo.gov.br/webhook')
        ->and($e->fresh()->callback_token)->toBe('segredo-123');
});
