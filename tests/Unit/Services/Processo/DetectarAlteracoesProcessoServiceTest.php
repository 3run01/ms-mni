<?php

use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\ProcessoMovimento;
use App\Models\Tribunal;
use App\Services\Processo\DetectarAlteracoesProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->service = new DetectarAlteracoesProcessoService();
    $this->processo = Processo::factory()->create([
        'tribunal_id' => Tribunal::factory()->create()->id,
    ]);
});

function criarMovimento(Processo $processo, string $identificador): ProcessoMovimento
{
    return ProcessoMovimento::create([
        'processo_id' => $processo->id,
        'identificador_movimento' => $identificador,
        'codigo_nacional' => 123,
        'complemento' => 'Complemento ' . $identificador,
        'data_hora' => '2026-08-12 09:12:00',
    ]);
}

function criarDocumento(Processo $processo, int $idDocumento): ProcessoDocumento
{
    return ProcessoDocumento::create([
        'processo_id' => $processo->id,
        'id_documento' => $idDocumento,
        'descricao' => 'Documento ' . $idDocumento,
        'tipo_documento' => '57',
        'mimetype' => 'application/pdf',
        'data_hora' => '2026-08-12 09:12:00',
        'nivel_sigilo' => 0,
    ]);
}

it('snapshot de processo null marca inexistente e delta vira primeira execução', function () {
    $snapshot = $this->service->snapshot(null);

    expect($snapshot)->toBe(['existia' => false, 'movimentos' => [], 'documentos' => []]);

    criarMovimento($this->processo, 'MOV-1');
    criarDocumento($this->processo, 900001);

    $delta = $this->service->delta($this->processo->fresh(), $snapshot);

    expect($delta['primeira_execucao'])->toBeTrue()
        ->and($delta['houve_alteracao'])->toBeTrue()
        ->and($delta['movimentos_novos'])->toBe(1)
        ->and($delta['documentos_novos'])->toBe(1);
});

it('delta traz só o movimento novo no formato do payload', function () {
    criarMovimento($this->processo, 'MOV-1');
    criarMovimento($this->processo, 'MOV-2');

    $snapshot = $this->service->snapshot($this->processo);

    criarMovimento($this->processo, 'MOV-3');

    $delta = $this->service->delta($this->processo->fresh(), $snapshot);

    expect($delta['movimentos_novos'])->toBe(1)
        ->and($delta['houve_alteracao'])->toBeTrue()
        ->and($delta['movimentos'][0]['identificador_movimento'])->toBe('MOV-3')
        ->and($delta['movimentos'][0]['codigo_nacional'])->toBe(123)
        ->and($delta['movimentos'][0]['complemento'])->toBe('Complemento MOV-3')
        ->and($delta['movimentos'][0]['data_hora'])->toContain('2026-08-12T09:12:00');
});

it('delta traz o documento novo com metadados', function () {
    criarDocumento($this->processo, 900001);

    $snapshot = $this->service->snapshot($this->processo);

    criarDocumento($this->processo, 900002);

    $delta = $this->service->delta($this->processo->fresh(), $snapshot);

    expect($delta['documentos_novos'])->toBe(1)
        ->and($delta['documentos'][0]['id_documento'])->toBe(900002)
        ->and($delta['documentos'][0]['descricao'])->toBe('Documento 900002')
        ->and($delta['documentos'][0]['mimetype'])->toBe('application/pdf')
        ->and($delta['documentos'][0])->toHaveKeys(['tipo_documento', 'data_hora', 'nivel_sigilo']);
});

it('sem novidade, houve_alteracao é false e listas vazias', function () {
    criarMovimento($this->processo, 'MOV-1');
    criarDocumento($this->processo, 900001);

    $snapshot = $this->service->snapshot($this->processo);
    $delta = $this->service->delta($this->processo->fresh(), $snapshot);

    expect($delta['houve_alteracao'])->toBeFalse()
        ->and($delta['primeira_execucao'])->toBeFalse()
        ->and($delta['movimentos'])->toBeEmpty()
        ->and($delta['documentos'])->toBeEmpty()
        ->and($delta['truncado'])->toBeFalse();
});

it('trunca as listas no limite e mantém contadores reais', function () {
    config()->set('pje.monitoramento.limite_itens_payload', 2);

    $snapshot = $this->service->snapshot($this->processo);

    foreach (range(1, 4) as $i) {
        criarMovimento($this->processo, "MOV-{$i}");
    }

    $delta = $this->service->delta($this->processo->fresh(), $snapshot);

    expect($delta['movimentos_novos'])->toBe(4)
        ->and($delta['movimentos'])->toHaveCount(2)
        ->and($delta['truncado'])->toBeTrue();
});
