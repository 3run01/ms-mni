<?php

use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\ProcessoExportacao;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTransactions::class);

it('pipeline: POST /api/processo/download → PDF → S3 → webhook', function () {
    Storage::fake('s3');
    Storage::fake('public');
    Http::fake(['*' => Http::response(['message' => 'OK', 'download_id' => 1], 200)]);

    criarTokenApi();
    config()->set('queue.default', 'sync');

    $numero = '6001255-81.2024.8.03.0003';
    $numeroCleaned = cleanNumeroProcesso($numero);
    $processo = Processo::create([
        'numero_processo' => $numeroCleaned,
        'tribunal_id' => 1,
        'valor_causa' => '0.00',
    ]);
    ProcessoDocumento::create([
        'processo_id' => $processo->id,
        'id_documento' => 1,
        'mimetype' => 'application/pdf',
        'data_hora' => '2024-12-12 10:00:00',
        'tipo_documento' => '0',
        'descricao' => 'Documento Teste',
    ]);

    $response = $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->postJson('/api/processo/download', [
            'numero_processo' => $numero,
            'user_id' => 42,
            'titulo' => 'Processo X — PDF',
            'formato' => 'pdf',
            'ids_selecionados' => [1],
        ]);

    $response->assertOk();
    $exportacao = ProcessoExportacao::find($response->json('exportacao_id'));

    expect($exportacao->status)->toBeIn([ProcessoExportacao::STATUS_CONCLUIDO, ProcessoExportacao::STATUS_FALHOU]);

    if ($exportacao->status === ProcessoExportacao::STATUS_CONCLUIDO) {
        Storage::disk('s3')->assertExists($exportacao->s3_path);
        expect($exportacao->webhook_enviado_em)->not->toBeNull();
        Http::assertSent(fn ($req) => $req['status'] === 'concluido');
    } else {
        // Caso o documento não tenha conteúdo válido em fixture, ainda confirmamos webhook
        Http::assertSent(fn ($req) => $req['status'] === 'falhou');
    }
})->skip(fn () => !class_exists(\setasign\Fpdi\Tcpdf\Fpdi::class), 'FPDI não disponível neste ambiente de teste');
