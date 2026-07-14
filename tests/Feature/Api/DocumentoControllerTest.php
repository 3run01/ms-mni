<?php

use App\Jobs\ConsultarDocumentosProcessoMNIJob;
use App\Models\Processo;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;

uses(DatabaseTransactions::class);

beforeEach(function () {
    criarTokenApi();
});

it('documento visualizar sem credenciais retorna 422', function () {
    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/documento/visualizar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&id_documento=123')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['login_pje', 'senha_pje']);
});

it('documentos listar sem credenciais retorna 422', function () {
    Processo::create([
        'numero_processo' => cleanNumeroProcesso('0600125-81.2024.8.03.0003'),
        'tribunal_id' => 1,
        'valor_causa' => '0.00',
    ]);

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/documentos/listar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['login_pje', 'senha_pje']);
});

it('documentos async sem credenciais retorna 422', function () {
    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar/documentos/async?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['login_pje', 'senha_pje']);
});

it('documentos async despacha job com as credenciais do payload', function () {
    Queue::fake();

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar/documentos/async?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&login_pje=u-pje&senha_pje=s-pje');

    Queue::assertPushed(
        ConsultarDocumentosProcessoMNIJob::class,
        fn ($job) => $job->login_pje === 'u-pje' && $job->senha_pje === 's-pje'
    );
});

it('listar documentos nao expoe conteudo_html nem path_html', function () {
    $numero = 'LISTALEVE' . getmypid();
    $processo = Processo::create([
        'numero_processo' => $numero,
        'tribunal_id' => 999999,
        'valor_causa' => '0.00',
    ]);
    \App\Models\ProcessoDocumento::create([
        'processo_id' => $processo->id,
        'id_documento' => 910001,
        'tipo_documento' => '0',
        'data_hora' => '2026-01-05 10:00:00',
        'descricao' => 'Sentenca Legada',
        'mimetype' => 'text/html',
        'status' => 'baixado',
    ]);
    // legado: coluna preenchida direto no banco (fora do fillable)
    \App\Models\ProcessoDocumento::where('id_documento', 910001)
        ->update(['conteudo_html' => '<html><body>Conteudo legado pesado</body></html>']);

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson("/api/processo/documentos/listar?tribunal_id=999999&numero_processo={$numero}&login_pje=u&senha_pje=s")
        ->assertOk()
        ->assertJsonPath('0.descricao', 'Sentenca Legada')
        ->assertJsonMissingPath('0.conteudo_html')
        ->assertJsonMissingPath('0.path_html');
});
