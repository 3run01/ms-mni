<?php

use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Services\Processo\SalvarDocumentoProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTransactions::class);

function criarDocumentoHtml(array $overrides = []): ProcessoDocumento
{
    $processo = Processo::factory()->create([
        'numero_processo' => 'SVCS3' . getmypid(),
    ]);

    return ProcessoDocumento::create(array_merge([
        'processo_id' => $processo->id,
        'id_documento' => 920001,
        'tipo_documento' => '0',
        'data_hora' => '2024-12-12 10:00:00',
        'descricao' => 'Documento HTML',
        'mimetype' => 'text/html',
        'status' => ProcessoDocumento::STATUS_PENDENTE,
    ], $overrides));
}

it('downloadHTML salva html e pdf no S3, seta path_html e nao escreve conteudo_html', function () {
    Storage::fake('s3');
    $documento = criarDocumentoHtml();
    $numero = $documento->processo->numero_processo;
    $html = '<html><body><h1>Sentenca</h1></body></html>';

    $service = Mockery::mock(SalvarDocumentoProcessoService::class)->makePartial();
    $service->shouldReceive('consultarDocumento')
        ->once()
        ->andReturn((object) ['conteudo' => base64_encode($html)]);

    $pathPdf = $service->downloadHTML($documento);

    expect($pathPdf)->toBe("documentos-processos/{$numero}/920001.pdf");
    Storage::disk('s3')->assertExists("documentos-processos/{$numero}/920001.pdf");
    Storage::disk('s3')->assertExists("documentos-processos/{$numero}/920001.html");
    expect(Storage::disk('s3')->get("documentos-processos/{$numero}/920001.html"))->toBe($html);

    $documento->refresh();
    expect($documento->path_html)->toBe("documentos-processos/{$numero}/920001.html")
        ->and($documento->conteudo_html)->toBeNull();
});
