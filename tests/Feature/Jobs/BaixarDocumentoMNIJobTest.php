<?php

use App\Jobs\BaixarDocumentoMNIJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Services\Processo\SalvarDocumentoProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

function criarDocumentoJob(array $overrides = []): ProcessoDocumento
{
    $processo = Processo::factory()->create([
        'numero_processo' => 'JOBDOC' . getmypid(),
    ]);

    return ProcessoDocumento::create(array_merge([
        'processo_id' => $processo->id,
        'id_documento' => 940001,
        'tipo_documento' => '0',
        'data_hora' => '2026-01-05 10:00:00',
        'descricao' => 'Documento Job',
        'mimetype' => 'text/html',
        'status' => ProcessoDocumento::STATUS_PENDENTE,
    ], $overrides));
}

it('handle marca STATUS_ERRO, incrementa tentativas_download e relanca quando o download falha', function () {
    $documento = criarDocumentoJob(['id_documento' => 940001]);

    $this->partialMock(SalvarDocumentoProcessoService::class, function ($mock) {
        $mock->shouldReceive('baixarDocumento')->once()->andThrow(new \Exception('MNI fora do ar'));
    });

    $job = new BaixarDocumentoMNIJob($documento);

    expect(fn () => $job->handle())->toThrow(\Exception::class, 'MNI fora do ar');

    $documento->refresh();
    expect($documento->status)->toBe(ProcessoDocumento::STATUS_ERRO)
        ->and($documento->tentativas_download)->toBe(1);
});

it('handle incrementa tentativas_download sem marcar erro quando o download tem sucesso', function () {
    $documento = criarDocumentoJob(['id_documento' => 940002]);

    $this->partialMock(SalvarDocumentoProcessoService::class, function ($mock) {
        $mock->shouldReceive('baixarDocumento')->once();
    });

    (new BaixarDocumentoMNIJob($documento))->handle();

    $documento->refresh();
    expect($documento->tentativas_download)->toBe(1)
        ->and($documento->status)->not->toBe(ProcessoDocumento::STATUS_ERRO);
});
