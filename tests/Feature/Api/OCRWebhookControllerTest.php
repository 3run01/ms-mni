<?php

use App\Jobs\JuntarOCRProcessoJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(DatabaseTransactions::class);

it('marca documento como processado ao receber webhook de sucesso', function () {
    Queue::fake();

    $processo = Processo::create([
        'numero_processo' => '0001001-00.2026.8.03.0001',
        'tribunal_id' => 2,
        'valor_causa' => '0.00',
        'knowledge_base_status_sync' => Processo::KNOWLEDGE_BASE_STATUS_STARTING,
    ]);

    $documento = ProcessoDocumento::create([
        'processo_id'    => $processo->id,
        'id_documento'   => 8001,
        'mimetype'       => 'application/pdf',
        'data_hora'      => now(),
        'tipo_documento' => '0',
        'hash'           => 'hash-test-001',
        'descricao'      => 'Documento teste 001',
        'status'         => ProcessoDocumento::STATUS_BAIXADO,
        'ocr_enviado_fila' => true,
        'ocr_processado' => false,
        'ocr_job_id'     => 'job-uuid-sucesso',
    ]);

    $response = $this->postJson('/api/ocr/webhook', [
        'status'       => 'success',
        'job_id'       => 'job-uuid-sucesso',
        'path_destino' => 'documentos-processos/0001001-00.2026.8.03.0001/8001.txt',
    ]);

    $response->assertStatus(200);

    $documento->refresh();
    expect($documento->ocr_processado)->toBeTrue();
    expect($documento->ocr_concluido_data)->not->toBeNull();
});

it('dispara JuntarOCRProcessoJob quando todos os documentos do processo estão prontos', function () {
    Queue::fake();

    $processo = Processo::create([
        'numero_processo' => '0002001-00.2026.8.03.0001',
        'tribunal_id' => 2,
        'valor_causa' => '0.00',
        'knowledge_base_status_sync' => Processo::KNOWLEDGE_BASE_STATUS_STARTING,
    ]);

    ProcessoDocumento::create([
        'processo_id'    => $processo->id,
        'id_documento'   => 8002,
        'mimetype'       => 'application/pdf',
        'data_hora'      => now(),
        'tipo_documento' => '0',
        'hash'           => 'hash-test-002',
        'descricao'      => 'Documento teste 002',
        'status'         => ProcessoDocumento::STATUS_BAIXADO,
        'ocr_processado' => true,
        'ocr_job_id'     => 'job-outro',
    ]);

    $docPendente = ProcessoDocumento::create([
        'processo_id'    => $processo->id,
        'id_documento'   => 8003,
        'mimetype'       => 'application/pdf',
        'data_hora'      => now(),
        'tipo_documento' => '0',
        'hash'           => 'hash-test-003',
        'descricao'      => 'Documento teste 003',
        'status'         => ProcessoDocumento::STATUS_BAIXADO,
        'ocr_enviado_fila' => true,
        'ocr_processado' => false,
        'ocr_job_id'     => 'job-uuid-ultimo',
    ]);

    $this->postJson('/api/ocr/webhook', [
        'status'  => 'success',
        'job_id'  => 'job-uuid-ultimo',
    ]);

    Queue::assertPushedOn('ocr', JuntarOCRProcessoJob::class);
});

it('retorna 404 para job_id desconhecido', function () {
    $response = $this->postJson('/api/ocr/webhook', [
        'status' => 'success',
        'job_id' => 'job-inexistente-xyz',
    ]);

    $response->assertStatus(404);
});

it('redefine ocr_enviado_fila ao receber webhook de erro', function () {
    Queue::fake();

    $processo = Processo::create([
        'numero_processo' => '0003001-00.2026.8.03.0001',
        'tribunal_id' => 2,
        'valor_causa' => '0.00',
    ]);

    $documento = ProcessoDocumento::create([
        'processo_id'    => $processo->id,
        'id_documento'   => 8004,
        'mimetype'       => 'application/pdf',
        'data_hora'      => now(),
        'tipo_documento' => '0',
        'hash'           => 'hash-test-004',
        'descricao'      => 'Documento teste 004',
        'status'         => ProcessoDocumento::STATUS_BAIXADO,
        'ocr_enviado_fila' => true,
        'ocr_processado' => false,
        'ocr_job_id'     => 'job-uuid-erro',
    ]);

    $response = $this->postJson('/api/ocr/webhook', [
        'status'       => 'error',
        'job_id'       => 'job-uuid-erro',
        'error_detail' => 'Timeout ao processar documento',
    ]);

    $response->assertStatus(200);

    $documento->refresh();
    expect($documento->ocr_enviado_fila)->toBeFalse();
    expect($documento->ocr_processado)->toBeFalse();
});

it('envia webhook de progresso ao sim quando documento é processado com sucesso', function () {
    Http::fake([
        '*/webhook/ocr-progresso' => Http::response(['message' => 'OK'], 200),
    ]);
    Queue::fake();

    $processo = Processo::create([
        'numero_processo'            => '0009001-00.2026.8.03.0001',
        'tribunal_id'                => 2,
        'valor_causa'                => '0.00',
        'knowledge_base_status_sync' => Processo::KNOWLEDGE_BASE_STATUS_STARTING,
    ]);

    ProcessoDocumento::create([
        'processo_id'      => $processo->id,
        'id_documento'     => 9001,
        'mimetype'         => 'application/pdf',
        'data_hora'        => now(),
        'tipo_documento'   => '0',
        'hash'             => 'hash-progresso-001',
        'descricao'        => 'Documento progresso 001',
        'status'           => ProcessoDocumento::STATUS_BAIXADO,
        'ocr_enviado_fila' => true,
        'ocr_processado'   => false,
        'ocr_job_id'       => 'job-progresso-001',
    ]);

    $this->postJson('/api/ocr/webhook', [
        'status' => 'success',
        'job_id' => 'job-progresso-001',
    ])->assertStatus(200);

    Http::assertSent(function ($request) use ($processo) {
        return str_contains($request->url(), '/webhook/ocr-progresso')
            && $request['numero_processo'] === $processo->numero_processo
            && $request->hasHeader('X-API-Token');
    });
});

it('não falha o webhook quando sim está indisponível', function () {
    Http::fake(function () {
        throw new \Illuminate\Http\Client\ConnectionException('timeout');
    });
    Queue::fake();

    $processo = Processo::create([
        'numero_processo' => '0099001-00.2026.8.03.0001',
        'tribunal_id'     => 2,
        'valor_causa'     => '0.00',
        'knowledge_base_status_sync' => Processo::KNOWLEDGE_BASE_STATUS_STARTING,
    ]);

    ProcessoDocumento::create([
        'processo_id'      => $processo->id,
        'id_documento'     => 9901,
        'mimetype'         => 'application/pdf',
        'data_hora'        => now(),
        'tipo_documento'   => '0',
        'hash'             => 'hash-silencio-001',
        'descricao'        => 'Documento silencio 001',
        'status'           => ProcessoDocumento::STATUS_BAIXADO,
        'ocr_enviado_fila' => true,
        'ocr_processado'   => false,
        'ocr_job_id'       => 'job-silencio-001',
    ]);

    $this->postJson('/api/ocr/webhook', [
        'status' => 'success',
        'job_id' => 'job-silencio-001',
    ])->assertStatus(200);
});
