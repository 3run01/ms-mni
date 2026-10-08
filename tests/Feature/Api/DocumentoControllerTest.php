<?php

use App\Http\Controllers\Api\DocumentoController;
use App\Jobs\ConsultarDocumentosProcessoMNIJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\Tribunal;
use App\Services\Processo\ProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;

uses(DatabaseTransactions::class);

beforeEach(function () {
    criarTokenApi();
    definirCredenciaisPadrao(null, null);
});

it('documentos listar sem credenciais usa o par padrao do .env', function () {
    definirCredenciaisPadrao('env-login', 'env-senha');
    $numero = 'LISTENV' . getmypid();

    $processo = Processo::create([
        'numero_processo' => $numero,
        'tribunal_id' => 999999,
        'valor_causa' => '0.00',
    ]);
    $processo->setRelation('documentos', collect());

    $this->mock(\App\Services\Processo\ProcessoService::class, function ($mock) use ($processo) {
        $mock->shouldReceive('consultarDocumentos')
            ->once()
            ->withArgs(fn ($tribunal, $num, $login, $senha, $dataRef) => $login === 'env-login' && $senha === 'env-senha')
            ->andReturn($processo);
    });

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson("/api/processo/documentos/listar?tribunal_id=999999&numero_processo={$numero}")
        ->assertOk();
});

it('documentos listar sem credenciais e sem par padrao repassa null ao ProcessoService', function () {
    $numero = 'LISTNULL' . getmypid();

    $processo = Processo::create([
        'numero_processo' => $numero,
        'tribunal_id' => 999999,
        'valor_causa' => '0.00',
    ]);
    $processo->setRelation('documentos', collect());

    $this->mock(\App\Services\Processo\ProcessoService::class, function ($mock) use ($processo) {
        $mock->shouldReceive('consultarDocumentos')
            ->once()
            ->withArgs(fn ($tribunal, $num, $login, $senha, $dataRef) => $login === null && $senha === null)
            ->andReturn($processo);
    });

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson("/api/processo/documentos/listar?tribunal_id=999999&numero_processo={$numero}")
        ->assertOk();
});

it('documentos async sem credenciais despacha job com o par padrao do .env', function () {
    Queue::fake();
    definirCredenciaisPadrao('env-login', 'env-senha');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar/documentos/async?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&callback_url=https://example.com/webhook&callback_token=tok-x')
        ->assertOk();

    Queue::assertPushed(
        ConsultarDocumentosProcessoMNIJob::class,
        fn ($job) => $job->login_pje === 'env-login' && $job->senha_pje === 'env-senha'
    );
});

it('documentos async sem callback continua retornando 422', function () {
    definirCredenciaisPadrao('env-login', 'env-senha');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar/documentos/async?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['callback_url', 'callback_token'])
        ->assertJsonMissingValidationErrors(['login_pje', 'senha_pje']);
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
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'apos 3 tentativas') || str_contains($m, 'após 3 tentativas'))
        ->assertJsonMissingPath('codigo');

    expect($chamadas)->toBe(3);
});

it('visualizar sem credenciais usa o par padrao do .env', function () {
    fakeS3ComLinks();
    definirCredenciaisPadrao('env-login', 'env-senha');
    $numero = 'VISENV' . getmypid();
    $documento = criarDocumentoVisualizar($numero, 930010);
    Storage::disk('s3')->put("documentos-processos/{$numero}/930010.pdf", 'pdf-fake');

    // baixarDocumento é o ponto onde as credenciais chegam no download do MNI
    $this->partialMock(\App\Services\Processo\SalvarDocumentoProcessoService::class, function ($mock) use ($documento) {
        $mock->shouldReceive('baixarDocumento')
            ->once()
            ->withArgs(fn ($doc, $login, $senha) => $login === 'env-login' && $senha === 'env-senha')
            ->andReturn($documento);
    });

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson("/api/documento/visualizar?tribunal_id=999999&numero_processo={$numero}&id_documento=930010")
        ->assertOk()
        ->assertJsonPath('documento.link', "https://s3.fake/documentos-processos/{$numero}/930010.pdf");
});

it('visualizar documento ausente no banco consulta o processo pelo ProcessoService injetado com o par da requisição', function () {
    $tribunal = Tribunal::factory()->create();
    $numero = 'VISAUSENTE' . getmypid();

    $this->mock(ProcessoService::class, function ($mock) use ($tribunal, $numero) {
        $mock->shouldReceive('consultarNumero')
            ->once()
            ->withArgs(fn ($tribunalRecebido, $numeroRecebido, $login, $senha) => $tribunalRecebido instanceof Tribunal
                && $tribunalRecebido->id === $tribunal->id
                && $numeroRecebido === $numero
                && $login === 'u-pje'
                && $senha === 's-pje')
            ->andReturnNull();
    });

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson("/api/documento/visualizar?tribunal_id={$tribunal->id}&numero_processo={$numero}&id_documento=930020&login_pje=u-pje&senha_pje=s-pje")
        ->assertStatus(500)
        ->assertExactJson(['message' => 'Documento não encontrado'])
        ->assertJsonMissingPath('codigo');
});

// ---------- erros MNI em /api/documento/visualizar ----------
// Caracterização do comportamento atual: nenhuma falha MNI devolve `codigo`.
// O tribunal é real e tem a senha cifrada, como no cadastro, porque sem par a
// consulta decifra a credencial do tribunal.

const LOGIN_RECUSADO_VISUALIZAR_DOCUMENTO_ERRO_MNI = 'Erro ao realizar login via MNI. exception invoking: loginFailed';
const LOGIN_PADRONIZADO_VISUALIZAR_DOCUMENTO_ERRO_MNI = 'Erro ao realizar login via MNI. Verifique suas credenciais.';
const PROCESSO_NAO_ENCONTRADO_VISUALIZAR_DOCUMENTO_ERRO_MNI = 'Processo de número 99999901120268030001 não encontrado!';

function urlVisualizarDocumentoErroMni(int $tribunalId, string $numeroProcesso, int $idDocumento, array $credenciais = []): string
{
    return '/api/documento/visualizar?' . http_build_query(array_merge([
        'tribunal_id' => $tribunalId,
        'numero_processo' => $numeroProcesso,
        'id_documento' => $idDocumento,
    ], $credenciais));
}

function semSucessoVisualizarDocumentoErroMni(string $mensagem): Closure
{
    return fn () => (object) ['sucesso' => false, 'mensagem' => $mensagem];
}

function tribunalVisualizarDocumentoErroMni(): Tribunal
{
    return Tribunal::factory()->create([
        'login' => 'login-do-tribunal',
        'password' => Crypt::encrypt('{SENHA}'),
        'ativo' => true,
    ]);
}

it('visualizar responde 404 sem codigo após 3 tentativas quando o link do documento baixado nunca é gerado', function () {
    Storage::fake('s3');
    $linksPedidos = 0;
    Storage::disk('s3')->buildTemporaryUrlsUsing(function () use (&$linksPedidos) {
        $linksPedidos++;

        throw new RuntimeException('Falha ao assinar a URL temporária');
    });
    [$processo, $documentos] = criarDocumentosLote([[
        'status' => ProcessoDocumento::STATUS_BAIXADO,
        'path' => 'documentos-processos/visualizar-rf05/documento.pdf',
    ]]);
    $documento = $documentos->first();
    Storage::disk('s3')->put($documento->path, 'pdf-fake');
    $simulador = simularIntegracaoMni(fn () => throw new RuntimeException('o MNI não deveria ser chamado'));

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson(urlVisualizarDocumentoErroMni($processo->tribunal_id, $processo->numero_processo, $documento->id_documento, ['login_pje' => 'login-cliente', 'senha_pje' => 'senha-cliente']))
        ->assertStatus(404)
        ->assertExactJson([
            'message' => "Não foi possível obter o documento {$documento->id_documento} do processo {$processo->numero_processo} após 3 tentativas",
        ])
        ->assertJsonMissingPath('codigo');

    // duas gerações de link por tentativa (antes e depois do baixarDocumento)
    expect($linksPedidos)->toBe(6)
        ->and($simulador->requisicoes)->toBe([]);
});

it('visualizar documento ausente com par do cliente responde 500 com o texto do tribunal quando a consulta do processo falha sem regra de padronização', function () {
    $tribunal = tribunalVisualizarDocumentoErroMni();
    $simulador = simularIntegracaoMni(semSucessoVisualizarDocumentoErroMni('MNI indisponível'));

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson(urlVisualizarDocumentoErroMni($tribunal->id, 'VISERRO' . getmypid() . 'C', 940001, ['login_pje' => 'login-cliente', 'senha_pje' => 'senha-cliente']))
        ->assertStatus(500)
        ->assertExactJson(['message' => 'MNI indisponível']);

    expect($simulador->requisicoes)->toHaveCount(1)
        ->and($simulador->requisicoes[0]['parametros'])->not->toHaveKey('documento')
        ->and($simulador->requisicoes[0]['parametros']['idConsultante'])->toBe('login-cliente');
});

it('visualizar documento ausente com o par padrão recusado responde 500 com a mensagem de login padronizada', function () {
    definirCredenciaisPadrao('env-login', 'env-senha');
    $tribunal = tribunalVisualizarDocumentoErroMni();
    $simulador = simularIntegracaoMni(semSucessoVisualizarDocumentoErroMni(LOGIN_RECUSADO_VISUALIZAR_DOCUMENTO_ERRO_MNI));

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson(urlVisualizarDocumentoErroMni($tribunal->id, 'VISERRO' . getmypid() . 'D', 940002))
        ->assertStatus(500)
        ->assertExactJson(['message' => LOGIN_PADRONIZADO_VISUALIZAR_DOCUMENTO_ERRO_MNI]);

    expect($simulador->requisicoes)->toHaveCount(1)
        ->and($simulador->requisicoes[0]['parametros'])->not->toHaveKey('documento')
        ->and($simulador->requisicoes[0]['parametros']['idConsultante'])->toBe('env-login')
        ->and($simulador->requisicoes[0]['parametros']['senhaConsultante'])->toBe('env-senha');
});

it('visualizar PDF pendente com o par padrão recusado no download responde 500 com a mensagem do wrapper sem o texto do tribunal', function () {
    Storage::fake('s3');
    definirCredenciaisPadrao('env-login', 'env-senha');
    [$processo, $documentos] = criarDocumentosLote(1);
    $documento = $documentos->first();
    $simulador = simularIntegracaoMni(semSucessoVisualizarDocumentoErroMni(LOGIN_RECUSADO_VISUALIZAR_DOCUMENTO_ERRO_MNI));

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson(urlVisualizarDocumentoErroMni($processo->tribunal_id, $processo->numero_processo, $documento->id_documento))
        ->assertStatus(500)
        ->assertExactJson(['message' => 'Erro ao processar documento PDF:']);

    expect($simulador->requisicoes)->toHaveCount(1)
        ->and($simulador->requisicoes[0]['parametros']['documento'])->toBe($documento->id_documento)
        ->and($simulador->requisicoes[0]['parametros']['idConsultante'])->toBe('env-login')
        ->and($simulador->requisicoes[0]['parametros']['senhaConsultante'])->toBe('env-senha');
});

it('visualizar PDF pendente com par do cliente responde 500 com a mensagem do wrapper quando o download falha sem regra de padronização', function () {
    Storage::fake('s3');
    [$processo, $documentos] = criarDocumentosLote(1);
    $documento = $documentos->first();
    $simulador = simularIntegracaoMni(semSucessoVisualizarDocumentoErroMni('MNI indisponível'));

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson(urlVisualizarDocumentoErroMni($processo->tribunal_id, $processo->numero_processo, $documento->id_documento, ['login_pje' => 'login-cliente', 'senha_pje' => 'senha-cliente']))
        ->assertStatus(500)
        ->assertExactJson(['message' => 'Erro ao processar documento PDF:']);

    expect($simulador->requisicoes)->toHaveCount(1)
        ->and($simulador->requisicoes[0]['parametros']['documento'])->toBe($documento->id_documento)
        ->and($simulador->requisicoes[0]['parametros']['idConsultante'])->toBe('login-cliente')
        ->and($simulador->requisicoes[0]['parametros']['senhaConsultante'])->toBe('senha-cliente');
});

it('visualizar HTML legado sem path_html nem conteudo_html responde 500 com a mensagem do wrapper quando a reconsulta pela credencial do tribunal falha', function (string $mensagem) {
    fakeS3ComLinks();
    [$processo, $documentos] = criarDocumentosLote([[
        'mimetype' => 'text/html',
        'status' => ProcessoDocumento::STATUS_BAIXADO,
        'path' => 'documentos-processos/visualizar-html-legado/documento.pdf',
    ]]);
    $documento = $documentos->first();
    Storage::disk('s3')->put($documento->path, 'pdf-fake');
    $simulador = simularIntegracaoMni(semSucessoVisualizarDocumentoErroMni($mensagem));

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson(urlVisualizarDocumentoErroMni($processo->tribunal_id, $processo->numero_processo, $documento->id_documento, ['login_pje' => 'login-cliente', 'senha_pje' => 'senha-cliente']))
        ->assertStatus(500)
        ->assertExactJson(['message' => 'Erro ao processar documento HTML:']);

    // a reconsulta do HTML legado não repassa o par da requisição
    expect($simulador->requisicoes)->toHaveCount(1)
        ->and($simulador->requisicoes[0]['parametros']['documento'])->toBe($documento->id_documento)
        ->and($simulador->requisicoes[0]['parametros']['idConsultante'])->toBe('login-do-tribunal')
        ->and($simulador->requisicoes[0]['parametros']['senhaConsultante'])->toBe('{SENHA}');
})->with([
    'login recusado' => LOGIN_RECUSADO_VISUALIZAR_DOCUMENTO_ERRO_MNI,
    'processo não encontrado' => PROCESSO_NAO_ENCONTRADO_VISUALIZAR_DOCUMENTO_ERRO_MNI,
]);

it('visualizar HTML com path_html ausente no S3 responde 200 com link e conteudo_html nulo quando a recuperação complementar falha', function (string $mensagem) {
    fakeS3ComLinks();
    [$processo, $documentos] = criarDocumentosLote([[
        'mimetype' => 'text/html',
        'status' => ProcessoDocumento::STATUS_BAIXADO,
        'path' => 'documentos-processos/visualizar-rf08/documento.pdf',
        'path_html' => 'documentos-processos/visualizar-rf08/documento.html',
    ]]);
    $documento = $documentos->first();
    // só o PDF existe: o .html sumiu e dispara a recuperação complementar
    Storage::disk('s3')->put($documento->path, 'pdf-fake');
    $simulador = simularIntegracaoMni(semSucessoVisualizarDocumentoErroMni($mensagem));

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson(urlVisualizarDocumentoErroMni($processo->tribunal_id, $processo->numero_processo, $documento->id_documento, ['login_pje' => 'login-cliente', 'senha_pje' => 'senha-cliente']))
        ->assertOk()
        ->assertJsonPath('documento.link', 'https://s3.fake/' . $documento->path)
        ->assertJsonPath('documento.conteudo_html', null)
        ->assertJsonMissingPath('codigo');

    expect($simulador->requisicoes)->toHaveCount(1)
        ->and($simulador->requisicoes[0]['parametros']['documento'])->toBe($documento->id_documento)
        ->and($simulador->requisicoes[0]['parametros']['idConsultante'])->toBe('login-cliente');
})->with([
    'login recusado' => LOGIN_RECUSADO_VISUALIZAR_DOCUMENTO_ERRO_MNI,
    'processo não encontrado' => PROCESSO_NAO_ENCONTRADO_VISUALIZAR_DOCUMENTO_ERRO_MNI,
]);

it('visualizar documento ausente com a credencial do tribunal recusada responde 500 com a mensagem de login padronizada', function () {
    // sem par na requisição e sem par padrão: a consulta usa a credencial do tribunal
    $tribunal = tribunalVisualizarDocumentoErroMni();
    $simulador = simularIntegracaoMni(semSucessoVisualizarDocumentoErroMni(LOGIN_RECUSADO_VISUALIZAR_DOCUMENTO_ERRO_MNI));

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson(urlVisualizarDocumentoErroMni($tribunal->id, 'VISERRO' . getmypid() . 'I', 940009))
        ->assertStatus(500)
        ->assertExactJson(['message' => LOGIN_PADRONIZADO_VISUALIZAR_DOCUMENTO_ERRO_MNI]);

    expect($simulador->requisicoes)->toHaveCount(1)
        ->and($simulador->requisicoes[0]['parametros'])->not->toHaveKey('documento')
        ->and($simulador->requisicoes[0]['parametros']['idConsultante'])->toBe('login-do-tribunal')
        ->and($simulador->requisicoes[0]['parametros']['senhaConsultante'])->toBe('{SENHA}');
});
