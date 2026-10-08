<?php

use App\Jobs\BaixarDocumentoMNIJob;
use App\Jobs\BaixarDocumentosMNIEmLoteJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\Tribunal;
use App\Services\Processo\SalvarDocumentoProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;

uses(DatabaseTransactions::class);

beforeEach(function () {
    Queue::fake();

    $this->service = new SalvarDocumentoProcessoService();
    $this->processo = Processo::factory()->create([
        'tribunal_id' => Tribunal::factory()->create()->id,
    ]);
});

function documentoStub(int $idDocumento, string $mimetype = 'application/pdf'): stdClass
{
    $doc = new stdClass();
    $doc->idDocumento = $idDocumento;
    $doc->tipoDocumento = '57';
    $doc->dataHora = '20260812091200';
    $doc->mimetype = $mimetype;
    $doc->descricao = 'Documento ' . $idDocumento;
    $doc->nivelSigilo = 0;

    return $doc;
}

it('com baixar_binarios false persiste o documento sem despachar download', function () {
    $this->service->execute($this->processo, [documentoStub(910001)], null, null, false);

    expect(ProcessoDocumento::where('id_documento', 910001)->exists())->toBeTrue();
    Queue::assertNotPushed(BaixarDocumentoMNIJob::class);
    Queue::assertNothingPushed();
});

it('default despacha o PDF num lote na fila mni-download, sem job por documento', function () {
    $this->service->execute($this->processo, [documentoStub(910002)]);

    $documento = ProcessoDocumento::where('processo_id', $this->processo->id)
        ->where('id_documento', 910002)
        ->sole();

    Queue::assertPushed(BaixarDocumentosMNIEmLoteJob::class, 1);
    Queue::assertPushed(BaixarDocumentosMNIEmLoteJob::class, fn ($job) => $job->queue === 'mni-download'
        && $job->processoId === $this->processo->id
        && $job->processoDocumentoIds === [$documento->id]);
    Queue::assertNotPushed(BaixarDocumentoMNIJob::class);
});

it('video mp4 segue com exatamente 1 job por documento, fora do lote', function () {
    $this->service->execute($this->processo, [documentoStub(910005, 'video/mp4')]);

    $documento = ProcessoDocumento::where('processo_id', $this->processo->id)
        ->where('id_documento', 910005)
        ->sole();

    Queue::assertPushed(BaixarDocumentoMNIJob::class, 1);
    Queue::assertPushed(BaixarDocumentoMNIJob::class, fn ($job) => $job->queue === 'mni-download'
        && (fn () => $this->documento->id)->call($job) === $documento->id);
    Queue::assertNotPushed(BaixarDocumentosMNIEmLoteJob::class);
});

it('a flag propaga para documentos vinculados', function () {
    $doc = documentoStub(910003);
    $doc->documentoVinculado = documentoStub(910004);

    $this->service->execute($this->processo, [$doc], null, null, false);

    expect(ProcessoDocumento::whereIn('id_documento', [910003, 910004])->count())->toBe(2);
    Queue::assertNotPushed(BaixarDocumentoMNIJob::class);
    Queue::assertNothingPushed();
});
