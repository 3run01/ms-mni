<?php

use App\Jobs\OCRRequestJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;

uses(DatabaseTransactions::class);

it('envia documento ao microserviço OCR e salva o job_id', function () {
    Http::fake([
        '*/documents/process' => Http::response(['id' => 'uuid-test-123', 'status' => 'pending'], 202),
    ]);

    config()->set('queue.default', 'sync');

    $processo = Processo::create([
        'numero_processo' => '0000001-00.2026.8.03.0001',
        'tribunal_id' => 2,
        'valor_causa' => '0.00',
    ]);

    $documento = ProcessoDocumento::create([
        'processo_id' => $processo->id,
        'id_documento' => 9001,
        'mimetype' => 'application/pdf',
        'data_hora' => now(),
        'tipo_documento' => '0',
        'descricao' => 'Documento teste OCR',
        'hash' => 'hash-test-9001',
        'path' => 'documentos-processos/0000001-00.2026.8.03.0001/9001.pdf',
        'status' => ProcessoDocumento::STATUS_BAIXADO,
    ]);

    config([
        'services.sim_ocr.url' => 'http://ocr.test',
        'services.sim_ocr.token' => 'token-test',
        'services.sim_ocr.bucket_origem' => 'bucket-origem-test',
        'services.sim_ocr.bucket_destino' => 'bucket-destino-test',
        'services.sim_ocr.webhook_url' => 'http://app.test/api/ocr/webhook',
    ]);

    (new OCRRequestJob($documento))->handle();

    Http::assertSent(function ($request) use ($documento, $processo) {
        return str_contains($request->url(), '/documents/process')
            && $request['bucket_origem'] === 'bucket-origem-test'
            && $request['path_origem'] === $documento->path
            && $request['bucket_destino'] === 'bucket-destino-test'
            && $request['path_destino'] === "documentos-processos/{$processo->numero_processo}/{$documento->id_documento}.txt"
            && $request['webhook_url'] === 'http://app.test/api/ocr/webhook';
    });

    $documento->refresh();
    expect($documento->ocr_job_id)->toBe('uuid-test-123');
    expect($documento->ocr_enviado_fila)->toBeTrue();
});

it('redefine ocr_enviado_fila ao falhar definitivamente', function () {
    $processo = Processo::create([
        'numero_processo' => '0000002-00.2026.8.03.0001',
        'tribunal_id' => 2,
        'valor_causa' => '0.00',
    ]);

    $documento = ProcessoDocumento::create([
        'processo_id' => $processo->id,
        'id_documento' => 9002,
        'mimetype' => 'application/pdf',
        'data_hora' => now(),
        'tipo_documento' => '0',
        'descricao' => 'Documento teste OCR falha',
        'hash' => 'hash-test-9002',
        'status' => ProcessoDocumento::STATUS_BAIXADO,
        'ocr_enviado_fila' => true,
    ]);

    $job = new OCRRequestJob($documento);
    $job->failed(new \Exception('falha simulada'));

    $documento->refresh();
    expect($documento->ocr_enviado_fila)->toBeFalse();
});
