<?php

use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Services\Processo\SalvarDocumentoProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('marcarComoBaixado seta status, path, file_size e downloaded_at', function () {
    $processo = Processo::factory()->create();
    $documento = ProcessoDocumento::create([
        'processo_id' => $processo->id,
        'id_documento' => 'DOC-DL-' . getmypid(),
        'tipo_documento' => 57,
        'descricao' => 'Doc teste downloaded_at',
        'mimetype' => 'application/pdf',
        'data_hora' => '2026-01-05 10:00:00',
        'status' => ProcessoDocumento::STATUS_PENDENTE,
    ]);

    app(SalvarDocumentoProcessoService::class)
        ->marcarComoBaixado($documento, 'documentos-processos/teste/1.pdf', 1234);

    $documento->refresh();
    expect($documento->status)->toBe(ProcessoDocumento::STATUS_BAIXADO)
        ->and($documento->getRawOriginal('path'))->toBe('documentos-processos/teste/1.pdf')
        ->and($documento->file_size)->toEqual(1234)
        ->and($documento->downloaded_at)->not->toBeNull();
});

it('marcarComoBaixado sem fileSize preserva file_size existente', function () {
    $processo = Processo::factory()->create();
    $documento = ProcessoDocumento::create([
        'processo_id' => $processo->id,
        'id_documento' => 'DOC-DL2-' . getmypid(),
        'tipo_documento' => 57,
        'descricao' => 'Doc teste sem filesize',
        'mimetype' => 'application/pdf',
        'data_hora' => '2026-01-05 10:00:00',
        'status' => ProcessoDocumento::STATUS_PENDENTE,
        'file_size' => 999,
    ]);

    app(SalvarDocumentoProcessoService::class)
        ->marcarComoBaixado($documento, 'documentos-processos/teste/2.pdf');

    $documento->refresh();
    expect($documento->file_size)->toEqual(999)
        ->and($documento->downloaded_at)->not->toBeNull();
});
