<?php

use App\Jobs\BaixarDocumentoMNIJob;
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

function documentoStub(int $idDocumento): stdClass
{
    $doc = new stdClass();
    $doc->idDocumento = $idDocumento;
    $doc->tipoDocumento = '57';
    $doc->dataHora = '20260812091200';
    $doc->mimetype = 'application/pdf';
    $doc->descricao = 'Documento ' . $idDocumento;
    $doc->nivelSigilo = 0;

    return $doc;
}

it('com baixar_binarios false persiste o documento sem despachar download', function () {
    $this->service->execute($this->processo, [documentoStub(910001)], null, null, false);

    expect(ProcessoDocumento::where('id_documento', 910001)->exists())->toBeTrue();
    Queue::assertNotPushed(BaixarDocumentoMNIJob::class);
});

it('default mantém o comportamento atual: despacha download na fila mni-download', function () {
    $this->service->execute($this->processo, [documentoStub(910002)]);

    Queue::assertPushed(BaixarDocumentoMNIJob::class, fn ($job) => $job->queue === 'mni-download');
});

it('a flag propaga para documentos vinculados', function () {
    $doc = documentoStub(910003);
    $doc->documentoVinculado = documentoStub(910004);

    $this->service->execute($this->processo, [$doc], null, null, false);

    expect(ProcessoDocumento::whereIn('id_documento', [910003, 910004])->count())->toBe(2);
    Queue::assertNotPushed(BaixarDocumentoMNIJob::class);
});
