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
