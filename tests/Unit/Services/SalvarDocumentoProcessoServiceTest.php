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

it('baixarDocumento re-baixa html quando documento baixado nao tem coluna nem path_html', function () {
    Storage::fake('s3');
    $documento = criarDocumentoHtml([
        'id_documento' => 920002,
        'status' => ProcessoDocumento::STATUS_BAIXADO,
        'path' => 'documentos-processos/x/920002.pdf',
    ]);
    Storage::disk('s3')->put('documentos-processos/x/920002.pdf', 'pdf-fake');

    $service = Mockery::mock(SalvarDocumentoProcessoService::class)->makePartial();
    $service->shouldReceive('downloadHTML')->once()->andReturn('documentos-processos/x/920002.pdf');

    $resultado = $service->baixarDocumento($documento);

    expect($resultado->id)->toBe($documento->id);
});

it('obterConteudoHtml retorna a coluna quando preenchida (legado), sem tocar o S3', function () {
    Storage::fake('s3');
    $documento = criarDocumentoHtml(['id_documento' => 920004]);
    ProcessoDocumento::where('id', $documento->id)
        ->update(['conteudo_html' => '<html><body>Legado</body></html>']);
    $documento->refresh();

    $service = new SalvarDocumentoProcessoService();

    expect($service->obterConteudoHtml($documento))->toBe('<html><body>Legado</body></html>');
});

it('obterConteudoHtml le do S3 via path_html quando a coluna esta vazia', function () {
    Storage::fake('s3');
    $documento = criarDocumentoHtml([
        'id_documento' => 920005,
        'path_html' => 'documentos-processos/x/920005.html',
    ]);
    Storage::disk('s3')->put('documentos-processos/x/920005.html', '<html><body>Novo</body></html>');

    $service = new SalvarDocumentoProcessoService();

    expect($service->obterConteudoHtml($documento))->toBe('<html><body>Novo</body></html>');
});

it('obterConteudoHtml retorna null quando path_html aponta para objeto inexistente', function () {
    Storage::fake('s3');
    $documento = criarDocumentoHtml([
        'id_documento' => 920006,
        'path_html' => 'documentos-processos/x/920006.html',
    ]);

    $service = new SalvarDocumentoProcessoService();

    expect($service->obterConteudoHtml($documento))->toBeNull();
});

it('obterConteudoHtml retorna null sem coluna e sem path_html', function () {
    Storage::fake('s3');
    $documento = criarDocumentoHtml(['id_documento' => 920007]);

    $service = new SalvarDocumentoProcessoService();

    expect($service->obterConteudoHtml($documento))->toBeNull();
});

it('baixarDocumento NAO re-baixa html quando documento baixado ja tem path_html', function () {
    Storage::fake('s3');
    $documento = criarDocumentoHtml([
        'id_documento' => 920003,
        'status' => ProcessoDocumento::STATUS_BAIXADO,
        'path' => 'documentos-processos/x/920003.pdf',
        'path_html' => 'documentos-processos/x/920003.html',
    ]);
    Storage::disk('s3')->put('documentos-processos/x/920003.pdf', 'pdf-fake');

    $service = Mockery::mock(SalvarDocumentoProcessoService::class)->makePartial();
    $service->shouldNotReceive('downloadHTML');

    $resultado = $service->baixarDocumento($documento);

    expect($resultado->id)->toBe($documento->id);
});
