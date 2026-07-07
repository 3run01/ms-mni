<?php

use App\Jobs\EnviarParaS3ExportacaoJob;
use App\Jobs\EnviarWebhookDownloadJob;
use App\Jobs\GerarPdfExportacaoJob;
use App\Models\ProcessoExportacao;
use App\Services\Exportacao\ExportacaoProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Mockery as m;

uses(DatabaseTransactions::class);

it('marca status processando, gera PDF e despacha S3 job em sucesso', function () {
    Queue::fake();

    $exportacao = ProcessoExportacao::factory()->create();

    $service = m::mock(ExportacaoProcessoService::class);
    $service->shouldReceive('consultarDocumentos')->once()->andReturn(new Collection([(object) ['id_documento' => 1]]));
    $service->shouldReceive('gerarPdf')->once()->andReturn('/tmp/fake.pdf');
    app()->instance(ExportacaoProcessoService::class, $service);

    (new GerarPdfExportacaoJob($exportacao->id))->handle(app(ExportacaoProcessoService::class));

    $exportacao->refresh();
    expect($exportacao->status)->toBe(ProcessoExportacao::STATUS_PROCESSANDO);
    Queue::assertPushed(EnviarParaS3ExportacaoJob::class, fn ($j) => $j->exportacaoId === $exportacao->id);
});

it('marca falhou e despacha webhook quando não há documentos', function () {
    Queue::fake();

    $exportacao = ProcessoExportacao::factory()->create();

    $service = m::mock(ExportacaoProcessoService::class)->makePartial();
    $service->shouldReceive('consultarDocumentos')->once()->andReturn(new Collection([]));
    $service->shouldReceive('marcarComoFalhou')->once()->withArgs(function ($e, $msg) use ($exportacao) {
        return $e->id === $exportacao->id && str_contains($msg, 'Nenhum documento');
    });
    app()->instance(ExportacaoProcessoService::class, $service);

    (new GerarPdfExportacaoJob($exportacao->id))->handle(app(ExportacaoProcessoService::class));
});

it('em falha de geração, chama marcarComoFalhou com a mensagem do erro', function () {
    Queue::fake();

    $exportacao = ProcessoExportacao::factory()->create();

    $service = m::mock(ExportacaoProcessoService::class)->makePartial();
    $service->shouldReceive('consultarDocumentos')->andReturn(new Collection([(object) ['id_documento' => 1]]));
    $service->shouldReceive('gerarPdf')->andThrow(new RuntimeException('boom no FPDI'));
    $service->shouldReceive('marcarComoFalhou')->once()->withArgs(function ($e, $msg) {
        return str_contains($msg, 'boom no FPDI');
    });
    app()->instance(ExportacaoProcessoService::class, $service);

    (new GerarPdfExportacaoJob($exportacao->id))->handle(app(ExportacaoProcessoService::class));
});

it('retorna sem efeito quando exportacao não existe', function () {
    Queue::fake();

    (new GerarPdfExportacaoJob(999999))->handle(app(ExportacaoProcessoService::class));

    Queue::assertNothingPushed();
});
