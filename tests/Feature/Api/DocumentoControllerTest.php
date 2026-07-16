<?php

use App\Http\Controllers\Api\DocumentoController;
use App\Jobs\ConsultarDocumentosProcessoMNIJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;

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
        ->getJson('/api/processo/consultar/documentos/async?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&login_pje=u-pje&senha_pje=s-pje&callback_url=https://example.com/webhook&callback_token=tok-x')
        ->assertOk();

    Queue::assertPushed(
        ConsultarDocumentosProcessoMNIJob::class,
        fn ($job) => $job->login_pje === 'u-pje' && $job->senha_pje === 's-pje'
            && $job->callback_url === 'https://example.com/webhook' && $job->callback_token === 'tok-x'
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

function criarDocumentoVisualizar(string $numero, int $idDocumento, array $overrides = []): ProcessoDocumento
{
    $processo = Processo::firstOrCreate(
        ['numero_processo' => $numero, 'tribunal_id' => 999999],
        ['valor_causa' => '0.00']
    );

    return ProcessoDocumento::create(array_merge([
        'processo_id' => $processo->id,
        'id_documento' => $idDocumento,
        'tipo_documento' => '0',
        'data_hora' => '2026-01-05 10:00:00',
        'descricao' => 'Documento Teste',
        'mimetype' => 'text/html',
        'status' => ProcessoDocumento::STATUS_BAIXADO,
        'path' => "documentos-processos/{$numero}/{$idDocumento}.pdf",
    ], $overrides));
}

function fakeS3ComLinks(): void
{
    Storage::fake('s3');
    Storage::disk('s3')->buildTemporaryUrlsUsing(
        fn ($path, $expiration, $options = []) => 'https://s3.fake/' . $path
    );
}

it('visualizar hidrata conteudo_html do S3 para documento novo', function () {
    fakeS3ComLinks();
    $numero = 'VIS' . getmypid() . 'A';
    $html = '<html><body>Conteudo novo no S3</body></html>';
    criarDocumentoVisualizar($numero, 930001, [
        'path_html' => "documentos-processos/{$numero}/930001.html",
    ]);
    Storage::disk('s3')->put("documentos-processos/{$numero}/930001.pdf", 'pdf-fake');
    Storage::disk('s3')->put("documentos-processos/{$numero}/930001.html", $html);

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson("/api/documento/visualizar?tribunal_id=999999&numero_processo={$numero}&id_documento=930001&login_pje=u&senha_pje=s")
        ->assertOk()
        ->assertJsonPath('documento.conteudo_html', $html)
        ->assertJsonPath('documento.link', "https://s3.fake/documentos-processos/{$numero}/930001.pdf")
        ->assertJsonMissingPath('documento.path_html')
        ->assertJsonMissingPath('documento.path');

    // invariante central: a hidratacao do S3 nunca repopula a coluna no banco
    expect(\App\Models\ProcessoDocumento::where('id_documento', 930001)->value('conteudo_html'))->toBeNull();
});

it('visualizar serve conteudo_html da coluna para documento legado', function () {
    fakeS3ComLinks();
    $numero = 'VIS' . getmypid() . 'B';
    $htmlLegado = '<html><body>Conteudo legado na coluna</body></html>';
    $documento = criarDocumentoVisualizar($numero, 930002);
    ProcessoDocumento::where('id', $documento->id)->update(['conteudo_html' => $htmlLegado]);
    Storage::disk('s3')->put("documentos-processos/{$numero}/930002.pdf", 'pdf-fake');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson("/api/documento/visualizar?tribunal_id=999999&numero_processo={$numero}&id_documento=930002&login_pje=u&senha_pje=s")
        ->assertOk()
        ->assertJsonPath('documento.conteudo_html', $htmlLegado);
});

it('visualizar mantem a chave conteudo_html nula para documento nao-HTML', function () {
    fakeS3ComLinks();
    $numero = 'VIS' . getmypid() . 'C';
    criarDocumentoVisualizar($numero, 930003, ['mimetype' => 'application/pdf']);
    Storage::disk('s3')->put("documentos-processos/{$numero}/930003.pdf", 'pdf-fake');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson("/api/documento/visualizar?tribunal_id=999999&numero_processo={$numero}&id_documento=930003&login_pje=u&senha_pje=s")
        ->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json
            ->where('documento.conteudo_html', null)
            ->etc());
});

it('visualizar responde conteudo_html nulo quando objeto sumiu do S3 e a auto-correcao falha', function () {
    fakeS3ComLinks();
    $numero = 'VIS' . getmypid() . 'D';
    // path_html aponta para objeto que nao existe; tribunal_id 999999 nao existe
    // na conexao sim, entao o re-download via MNI falha sem tocar a rede.
    criarDocumentoVisualizar($numero, 930004, [
        'path_html' => "documentos-processos/{$numero}/930004.html",
    ]);
    Storage::disk('s3')->put("documentos-processos/{$numero}/930004.pdf", 'pdf-fake');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson("/api/documento/visualizar?tribunal_id=999999&numero_processo={$numero}&id_documento=930004&login_pje=u&senha_pje=s")
        ->assertOk()
        ->assertJsonPath('documento.link', "https://s3.fake/documentos-processos/{$numero}/930004.pdf")
        ->assertJson(fn (AssertableJson $json) => $json
            ->where('documento.conteudo_html', null)
            ->etc());
});

it('visualizar re-hidrata via auto-correcao quando o objeto sumiu do S3 mas o MNI responde', function () {
    fakeS3ComLinks();
    $numero = 'VIS' . getmypid() . 'E';
    $html = '<html><body>Recuperado via auto-correcao</body></html>';
    criarDocumentoVisualizar($numero, 930005, [
        'path_html' => "documentos-processos/{$numero}/930005.html",
    ]);
    // PDF existe (para gerar o link), mas o .html sumiu do S3 => dispara a auto-correcao
    Storage::disk('s3')->put("documentos-processos/{$numero}/930005.pdf", 'pdf-fake');

    // Mocka apenas a chamada MNI; o resto do downloadHTML roda de verdade
    $this->partialMock(\App\Services\Processo\SalvarDocumentoProcessoService::class, function ($mock) use ($html) {
        $mock->shouldReceive('consultarDocumento')
            ->andReturn((object) ['conteudo' => base64_encode($html)]);
    });

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson("/api/documento/visualizar?tribunal_id=999999&numero_processo={$numero}&id_documento=930005&login_pje=u&senha_pje=s")
        ->assertOk()
        ->assertJsonPath('documento.conteudo_html', $html)
        ->assertJsonPath('documento.link', "https://s3.fake/documentos-processos/{$numero}/930005.pdf");

    // invariante central da feature: a coluna no banco permanece vazia
    expect(\App\Models\ProcessoDocumento::where('id_documento', 930005)->value('conteudo_html'))->toBeNull();
});

it('show retorna 404 apos maxTentativas quando o documento nunca resolve link (sem loop infinito)', function () {
    // getDocumento sempre retorna null (documento sem link). A valvula de seguranca
    // estoura se chamado vezes demais, provando que o do-while termina em vez de loopar.
    $chamadas = 0;
    $this->partialMock(DocumentoController::class, function ($mock) use (&$chamadas) {
        $mock->shouldReceive('getDocumento')->andReturnUsing(function () use (&$chamadas) {
            $chamadas++;
            if ($chamadas > 10) {
                throw new \RuntimeException('getDocumento chamado vezes demais — provavel loop infinito');
            }

            return null;
        });
    });

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/documento/visualizar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&id_documento=456&login_pje=u&senha_pje=s')
        ->assertStatus(404)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'apos 3 tentativas') || str_contains($m, 'após 3 tentativas'));

    expect($chamadas)->toBe(3);
});
