<?php

use App\Models\Processo;
use App\Models\ProcessoDocumento;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(DatabaseTransactions::class);

it('notifica o sim sobre progresso ao processar documento via polling', function () {
    Http::fake([
        '*/jobs/*'              => Http::response(['status' => 'success'], 200),
        '*/webhook/ocr-progresso' => Http::response(['message' => 'OK'], 200),
    ]);
    Queue::fake();

    $processo = Processo::create([
        'numero_processo'            => cleanNumeroProcesso('0010001-00.2026.8.03.0001'),
        'tribunal_id'                => 2,
        'valor_causa'                => '0.00',
        'knowledge_base_status_sync' => Processo::KNOWLEDGE_BASE_STATUS_STARTING,
    ]);

    ProcessoDocumento::create([
        'processo_id'      => $processo->id,
        'id_documento'     => 10001,
        'mimetype'         => 'application/pdf',
        'data_hora'        => now(),
        'tipo_documento'   => '0',
        'hash'             => 'hash-poll-001',
        'descricao'        => 'Documento poll 001',
        'status'           => ProcessoDocumento::STATUS_BAIXADO,
        'ocr_enviado_fila' => true,
        'ocr_processado'   => false,
        'ocr_job_id'       => 'job-poll-uuid-001',
    ]);

    $this->artisan('ocr:poll-status', [
        '--processo' => $processo->numero_processo,
    ])->assertSuccessful();

    Http::assertSent(function ($request) use ($processo) {
        return str_contains($request->url(), '/webhook/ocr-progresso')
            && $request['numero_processo'] === $processo->numero_processo
            && $request->hasHeader('X-API-Token');
    });
});
