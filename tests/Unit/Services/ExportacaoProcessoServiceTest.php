<?php

use App\Jobs\EnviarWebhookDownloadJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\ProcessoExportacao;
use App\Services\Exportacao\ExportacaoProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Storage as StorageFacade;

uses(DatabaseTransactions::class);

function criarProcessoComDocumentos(string $numero, array $docs): Processo
{
    $processo = Processo::create([
        'numero_processo' => $numero,
        'tribunal_id' => 1,
        'valor_causa' => '0.00',
    ]);

    foreach ($docs as $doc) {
        ProcessoDocumento::create(array_merge([
            'processo_id' => $processo->id,
            'mimetype' => 'application/pdf',
            'tipo_documento' => '0',
            'descricao' => 'documento teste',
        ], $doc));
    }

    return $processo;
}

it('criar() cria registro com status enfileirado e filtros serializados', function () {
    $service = new ExportacaoProcessoService();

    $exportacao = $service->criar([
        'user_id' => 42,
        'numero_processo' => '6001255-81.2024.8.03.0003',
        'tribunal_id' => 2,
        'titulo' => 'Processo X — PDF',
        'formato' => 'pdf',
        'ids_selecionados' => [1, 2],
        'periodo_inicial' => null,
        'periodo_final' => null,
        'id_inicial' => null,
        'id_final' => null,
    ]);

    expect($exportacao->user_id)->toBe(42);
    expect($exportacao->status)->toBe(ProcessoExportacao::STATUS_ENFILEIRADO);
    expect($exportacao->filtros)->toBe([
        'ids_selecionados' => [1, 2],
        'periodo_inicial' => null,
        'periodo_final' => null,
        'id_inicial' => null,
        'id_final' => null,
    ]);
});

it('temDocumentosDisponiveis() retorna true quando há ao menos 1 documento aplicável', function () {
    $numero = '6001255-81.2024.8.03.0003';
    criarProcessoComDocumentos($numero, [
        ['id_documento' => 100, 'data_hora' => '2024-12-12 10:00:00', 'mimetype' => 'application/pdf'],
    ]);

    $service = new ExportacaoProcessoService();

    expect($service->temDocumentosDisponiveis(['ids_selecionados' => [100]], $numero))->toBeTrue();
});

it('temDocumentosDisponiveis() retorna false quando ids não casam', function () {
    $numero = '6001255-81.2024.8.03.0003';
    criarProcessoComDocumentos($numero, [
        ['id_documento' => 100, 'data_hora' => '2024-12-12 10:00:00', 'mimetype' => 'application/pdf'],
    ]);

    $service = new ExportacaoProcessoService();

    expect($service->temDocumentosDisponiveis(['ids_selecionados' => [999]], $numero))->toBeFalse();
});

it('consultarDocumentos() filtra por ids_selecionados', function () {
    $numero = '6001255-81.2024.8.03.0003';
    criarProcessoComDocumentos($numero, [
        ['id_documento' => 1, 'data_hora' => '2024-12-12 10:00:00', 'mimetype' => 'application/pdf'],
        ['id_documento' => 2, 'data_hora' => '2024-12-13 10:00:00', 'mimetype' => 'application/pdf'],
        ['id_documento' => 3, 'data_hora' => '2024-12-14 10:00:00', 'mimetype' => 'application/pdf'],
    ]);

    $exportacao = ProcessoExportacao::factory()->create([
        'numero_processo' => $numero,
        'filtros' => ['ids_selecionados' => [1, 3]],
    ]);

    $docs = (new ExportacaoProcessoService())->consultarDocumentos($exportacao);

    expect($docs)->toHaveCount(2);
    expect($docs->pluck('id_documento')->all())->toEqualCanonicalizing([1, 3]);
});

it('consultarDocumentos() filtra por periodo', function () {
    $numero = '6001255-81.2024.8.03.0003';
    criarProcessoComDocumentos($numero, [
        ['id_documento' => 1, 'data_hora' => '2024-12-10 10:00:00', 'mimetype' => 'application/pdf'],
        ['id_documento' => 2, 'data_hora' => '2024-12-12 10:00:00', 'mimetype' => 'application/pdf'],
        ['id_documento' => 3, 'data_hora' => '2024-12-15 10:00:00', 'mimetype' => 'application/pdf'],
    ]);

    $exportacao = ProcessoExportacao::factory()->create([
        'numero_processo' => $numero,
        'filtros' => [
            'ids_selecionados' => null,
            'periodo_inicial' => '2024-12-12',
            'periodo_final' => '2024-12-13',
            'id_inicial' => null,
            'id_final' => null,
        ],
    ]);

    $docs = (new ExportacaoProcessoService())->consultarDocumentos($exportacao);

    expect($docs)->toHaveCount(1);
    expect($docs->first()->id_documento)->toBe(2);
});

it('consultarDocumentos() ignora mimetypes não suportados', function () {
    $numero = '6001255-81.2024.8.03.0003';
    criarProcessoComDocumentos($numero, [
        ['id_documento' => 1, 'data_hora' => '2024-12-12 10:00:00', 'mimetype' => 'image/png'],
        ['id_documento' => 2, 'data_hora' => '2024-12-12 10:00:00', 'mimetype' => 'application/pdf'],
    ]);

    $exportacao = ProcessoExportacao::factory()->create([
        'numero_processo' => $numero,
        'filtros' => ['ids_selecionados' => [1, 2]],
    ]);

    $docs = (new ExportacaoProcessoService())->consultarDocumentos($exportacao);

    expect($docs)->toHaveCount(1);
    expect($docs->first()->id_documento)->toBe(2);
});

it('consultarDocumentos() retorna documentos em ordem crescente por id_documento', function () {
    $numero = '6001255-81.2024.8.03.0003';
    criarProcessoComDocumentos($numero, [
        ['id_documento' => 30, 'data_hora' => '2024-12-14 10:00:00', 'mimetype' => 'application/pdf', 'status' => ProcessoDocumento::STATUS_BAIXADO, 'path' => 'docs/30.pdf'],
        ['id_documento' => 10, 'data_hora' => '2024-12-12 10:00:00', 'mimetype' => 'application/pdf', 'status' => ProcessoDocumento::STATUS_BAIXADO, 'path' => 'docs/10.pdf'],
        ['id_documento' => 20, 'data_hora' => '2024-12-13 10:00:00', 'mimetype' => 'application/pdf', 'status' => ProcessoDocumento::STATUS_BAIXADO, 'path' => 'docs/20.pdf'],
    ]);

    $exportacao = ProcessoExportacao::factory()->create([
        'numero_processo' => $numero,
        'filtros' => ['ids_selecionados' => [10, 20, 30]],
    ]);

    $docs = (new ExportacaoProcessoService())->consultarDocumentos($exportacao);

    expect($docs->pluck('id_documento')->all())->toBe([10, 20, 30]);
});

it('consultarDocumentos() retorna todos os documentos PDF/HTML quando filtros é vazio', function () {
    $numero = '6001255-81.2024.8.03.0003';
    criarProcessoComDocumentos($numero, [
        ['id_documento' => 10, 'data_hora' => '2024-12-10 10:00:00', 'mimetype' => 'application/pdf'],
        ['id_documento' => 11, 'data_hora' => '2024-12-11 10:00:00', 'mimetype' => 'text/html'],
        ['id_documento' => 12, 'data_hora' => '2024-12-12 10:00:00', 'mimetype' => 'image/png'],
    ]);

    $exportacao = ProcessoExportacao::factory()->create([
        'numero_processo' => $numero,
        'filtros' => [],
    ]);

    $docs = (new ExportacaoProcessoService())->consultarDocumentos($exportacao);

    expect($docs)->toHaveCount(2);
    expect($docs->pluck('id_documento')->all())->toEqualCanonicalizing([10, 11]);
});

it('marcarComoFalhou() atualiza registro e despacha webhook', function () {
    Queue::fake();

    $exportacao = ProcessoExportacao::factory()->processando()->create();

    (new ExportacaoProcessoService())->marcarComoFalhou($exportacao, 'algo deu errado');

    $exportacao->refresh();
    expect($exportacao->status)->toBe(ProcessoExportacao::STATUS_FALHOU);
    expect($exportacao->erro_resumo)->toBe('algo deu errado');

    Queue::assertPushed(EnviarWebhookDownloadJob::class, fn ($job) => $job->exportacaoId === $exportacao->id);
});

it('gerarPdf() persiste uuid_arquivo e cria arquivo PDF no caminho esperado', function () {
    StorageFacade::fake('public');
    StorageFacade::fake('s3');

    $numero = '6001255-81.2024.8.03.0003';
    $processo = criarProcessoComDocumentos($numero, []);

    $exportacao = ProcessoExportacao::factory()->create([
        'numero_processo' => $numero,
        'tribunal_id' => $processo->tribunal_id,
    ]);

    $service = new ExportacaoProcessoService();
    $documentos = $service->consultarDocumentos($exportacao); // collection vazia

    $caminho = $service->gerarPdf($exportacao, $documentos);

    $exportacao->refresh();
    expect($exportacao->uuid_arquivo)->not->toBeNull();
    expect($caminho)->toBe(storage_path("app/private/exportacoes/{$exportacao->uuid_arquivo}.pdf"));
    expect(file_exists($caminho))->toBeTrue();

    @unlink($caminho);
})->skip(fn () => !class_exists(\setasign\Fpdi\Tcpdf\Fpdi::class), 'FPDI não disponível neste ambiente de teste');

it('enviarParaS3() faz upload no path downloads/{user_id}/{uuid}.pdf, atualiza s3_path e tamanho_bytes, marca concluido e remove arquivo local', function () {
    Storage::fake('s3');

    $exportacao = ProcessoExportacao::factory()->processando()->create([
        'user_id' => 99,
    ]);

    $caminhoLocal = storage_path("app/private/exportacoes/{$exportacao->uuid_arquivo}.pdf");
    @mkdir(dirname($caminhoLocal), 0755, true);
    file_put_contents($caminhoLocal, str_repeat('x', 2048));

    (new ExportacaoProcessoService())->enviarParaS3($exportacao, $caminhoLocal);

    $exportacao->refresh();
    expect($exportacao->status)->toBe(ProcessoExportacao::STATUS_CONCLUIDO);
    expect($exportacao->s3_path)->toBe("downloads/99/{$exportacao->uuid_arquivo}.pdf");
    expect($exportacao->tamanho_bytes)->toBe(2048);
    Storage::disk('s3')->assertExists($exportacao->s3_path);
    expect(file_exists($caminhoLocal))->toBeFalse();
});
