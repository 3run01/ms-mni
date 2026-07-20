<?php

use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTransactions::class);

function loginVisualizar(): User
{
    return User::factory()->make(['id' => 1]);
}

function documentoBaixado(Processo $processo, array $overrides = []): ProcessoDocumento
{
    return ProcessoDocumento::create(array_merge([
        'processo_id' => $processo->id,
        'id_documento' => 'DOC-VIS-' . uniqid(),
        'tipo_documento' => 57,
        'descricao' => 'Documento de teste',
        'mimetype' => 'application/pdf',
        'data_hora' => '2026-01-05 10:00:00',
        'status' => ProcessoDocumento::STATUS_BAIXADO,
        'file_size' => 2048,
        'path' => 'documentos/teste.pdf',
    ], $overrides));
}

it('redireciona visitante para o login', function () {
    $processo = Processo::factory()->create();
    $documento = documentoBaixado($processo);

    $this->get("/processos/{$processo->id}/documentos/{$documento->id}")
        ->assertRedirect('/login');
});

it('serve conteudo html para documento text/html baixado', function () {
    Storage::fake('s3');
    $processo = Processo::factory()->create();
    $documento = documentoBaixado($processo, [
        'mimetype' => 'text/html',
        'path' => null,
        'path_html' => 'documentos/teste.html',
    ]);
    Storage::disk('s3')->put('documentos/teste.html', '<p>Sentença de teste</p>');

    $this->actingAs(loginVisualizar())
        ->get("/processos/{$processo->id}/documentos/{$documento->id}")
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=utf-8')
        ->assertSee('Sentença de teste', false);
});

it('redireciona para url temporaria do s3 para documento pdf baixado', function () {
    Storage::fake('s3');
    Storage::disk('s3')->put('documentos/teste.pdf', '%PDF-fake');
    Storage::disk('s3')->buildTemporaryUrlsUsing(
        fn ($path) => 'https://s3.fake/' . $path . '?assinada=1'
    );

    $processo = Processo::factory()->create();
    $documento = documentoBaixado($processo);

    $this->actingAs(loginVisualizar())
        ->get("/processos/{$processo->id}/documentos/{$documento->id}")
        ->assertRedirect('https://s3.fake/documentos/teste.pdf?assinada=1');
});

it('retorna 404 para documento nao baixado', function () {
    $processo = Processo::factory()->create();
    $documento = documentoBaixado($processo, ['status' => ProcessoDocumento::STATUS_PENDENTE]);

    $this->actingAs(loginVisualizar())
        ->get("/processos/{$processo->id}/documentos/{$documento->id}")
        ->assertNotFound();
});

it('retorna 404 para documento de outro processo (binding escopado)', function () {
    $processoA = Processo::factory()->create();
    $processoB = Processo::factory()->create();
    $documentoDeB = documentoBaixado($processoB);

    $this->actingAs(loginVisualizar())
        ->get("/processos/{$processoA->id}/documentos/{$documentoDeB->id}")
        ->assertNotFound();
});

it('retorna 404 para documento html sem conteudo recuperavel', function () {
    Storage::fake('s3');
    $processo = Processo::factory()->create();
    $documento = documentoBaixado($processo, [
        'mimetype' => 'text/html',
        'path' => null,
        'path_html' => null,
    ]);

    $this->actingAs(loginVisualizar())
        ->get("/processos/{$processo->id}/documentos/{$documento->id}")
        ->assertNotFound();
});

it('retorna 404 para pdf cujo arquivo nao existe no s3', function () {
    Storage::fake('s3');
    $processo = Processo::factory()->create();
    $documento = documentoBaixado($processo, ['path' => 'documentos/sumiu.pdf']);

    $this->actingAs(loginVisualizar())
        ->get("/processos/{$processo->id}/documentos/{$documento->id}")
        ->assertNotFound();
});
