<?php

use App\Exceptions\CredencialPjeRecusadaException;
use App\Exceptions\MNIException;
use App\Exceptions\ProcessoNaoEncontradoNoTribunalException;
use App\Jobs\BaixarDocumentoMNIJob;
use App\Jobs\BaixarDocumentosMNIEmLoteJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Services\Processo\SalvarDocumentoProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTransactions::class);

function criarDocumentoHtml(array $overrides = []): ProcessoDocumento
{
    $processo = Processo::factory()->create([
        'numero_processo' => 'SVCS3' . getmypid(),
    ]);

    return ProcessoDocumento::create(array_merge([
        'processo_id' => $processo->id,
        'id_documento' => 920001,
        'tipo_documento' => '0',
        'data_hora' => '2024-12-12 10:00:00',
        'descricao' => 'Documento HTML',
        'mimetype' => 'text/html',
        'status' => ProcessoDocumento::STATUS_PENDENTE,
    ], $overrides));
}

it('execute enfileira documento e vinculado num unico lote com as credenciais pje', function () {
    Queue::fake();
    $processo = Processo::factory()->create([
        'numero_processo' => 'SVCS3CRED' . getmypid(),
    ]);
    $documento = (object) [
        'idDocumento' => 930001,
        'tipoDocumento' => '57',
        'dataHora' => '2024-12-12 10:00:00',
        'mimetype' => 'application/pdf',
        'descricao' => 'Peticao',
        'documentoVinculado' => (object) [
            'idDocumento' => 930002,
            'idDocumentoVinculado' => 930001,
            'tipoDocumento' => '57',
            'dataHora' => '2024-12-12 10:00:00',
            'mimetype' => 'application/pdf',
            'descricao' => 'Anexo',
        ],
    ];

    $service = new SalvarDocumentoProcessoService();
    $service->execute($processo, [$documento], '{LOGIN}', '{SENHA}');

    $ids = ProcessoDocumento::where('processo_id', $processo->id)
        ->whereIn('id_documento', [930001, 930002])
        ->orderBy('id_documento')
        ->pluck('id')
        ->all();

    $lote = Queue::pushed(BaixarDocumentosMNIEmLoteJob::class)->sole();

    expect($lote->queue)->toBe('mni-download')
        ->and($lote->processoId)->toBe($processo->id)
        ->and($lote->processoDocumentoIds)->toEqualCanonicalizing($ids)
        ->and((fn () => [$this->login_pje, $this->senha_pje])->call($lote))->toBe(['{LOGIN}', '{SENHA}']);

    Queue::assertNotPushed(BaixarDocumentoMNIJob::class);
});

it('execute nao enfileira documento ja baixado', function () {
    Queue::fake();
    $processo = Processo::factory()->create([
        'numero_processo' => 'SVCS3BAIX' . getmypid(),
    ]);
    $baixado = ProcessoDocumento::create([
        'processo_id' => $processo->id,
        'id_documento' => 930011,
        'tipo_documento' => '57',
        'data_hora' => '2024-12-12 10:00:00',
        'descricao' => 'Ja baixado',
        'mimetype' => 'application/pdf',
        'status' => ProcessoDocumento::STATUS_BAIXADO,
        'path' => 'documentos-processos/x/930011.pdf',
    ]);
    $documentoMni = fn (int $id) => (object) [
        'idDocumento' => $id,
        'tipoDocumento' => '57',
        'dataHora' => '2024-12-12 10:00:00',
        'mimetype' => 'application/pdf',
        'descricao' => 'Peticao',
    ];

    (new SalvarDocumentoProcessoService())->execute(
        $processo,
        [$documentoMni(930011), $documentoMni(930012)],
        '{LOGIN}',
        '{SENHA}',
    );

    $novo = ProcessoDocumento::where('processo_id', $processo->id)->where('id_documento', 930012)->sole();
    $lote = Queue::pushed(BaixarDocumentosMNIEmLoteJob::class)->sole();

    expect($lote->processoDocumentoIds)->toBe([$novo->id])
        ->and($lote->processoDocumentoIds)->not->toContain($baixado->id);

    Queue::assertNotPushed(BaixarDocumentoMNIJob::class);
});

it('downloadHTML salva html e pdf no S3, seta path_html e nao escreve conteudo_html', function () {
    Storage::fake('s3');
    $documento = criarDocumentoHtml();
    $numero = $documento->processo->numero_processo;
    $html = '<html><body><h1>Sentenca</h1></body></html>';

    $service = Mockery::mock(SalvarDocumentoProcessoService::class)->makePartial();
    $service->shouldReceive('consultarDocumento')
        ->once()
        ->andReturn((object) ['conteudo' => base64_encode($html)]);

    $pathPdf = $service->downloadHTML($documento);

    expect($pathPdf)->toBe("documentos-processos/{$numero}/920001.pdf");
    Storage::disk('s3')->assertExists("documentos-processos/{$numero}/920001.pdf");
    Storage::disk('s3')->assertExists("documentos-processos/{$numero}/920001.html");
    expect(Storage::disk('s3')->get("documentos-processos/{$numero}/920001.html"))->toBe($html);

    $documento->refresh();
    expect($documento->path_html)->toBe("documentos-processos/{$numero}/920001.html")
        ->and($documento->conteudo_html)->toBeNull();
});

it('baixarDocumento re-baixa html quando documento baixado nao tem coluna nem path_html', function () {
    Storage::fake('s3');
    $documento = criarDocumentoHtml([
        'id_documento' => 920002,
        'status' => ProcessoDocumento::STATUS_BAIXADO,
        'path' => 'documentos-processos/x/920002.pdf',
    ]);
    Storage::disk('s3')->put('documentos-processos/x/920002.pdf', 'pdf-fake');

    $service = Mockery::mock(SalvarDocumentoProcessoService::class)->makePartial();
    $service->shouldReceive('downloadHTML')->once()->andReturn('documentos-processos/x/920002.pdf');

    $resultado = $service->baixarDocumento($documento);

    expect($resultado->id)->toBe($documento->id);
});

it('obterConteudoHtml retorna a coluna quando preenchida (legado), sem tocar o S3', function () {
    Storage::fake('s3');
    $documento = criarDocumentoHtml(['id_documento' => 920004]);
    ProcessoDocumento::where('id', $documento->id)
        ->update(['conteudo_html' => '<html><body>Legado</body></html>']);
    $documento->refresh();

    $service = new SalvarDocumentoProcessoService();

    expect($service->obterConteudoHtml($documento))->toBe('<html><body>Legado</body></html>');
});

it('obterConteudoHtml le do S3 via path_html quando a coluna esta vazia', function () {
    Storage::fake('s3');
    $documento = criarDocumentoHtml([
        'id_documento' => 920005,
        'path_html' => 'documentos-processos/x/920005.html',
    ]);
    Storage::disk('s3')->put('documentos-processos/x/920005.html', '<html><body>Novo</body></html>');

    $service = new SalvarDocumentoProcessoService();

    expect($service->obterConteudoHtml($documento))->toBe('<html><body>Novo</body></html>');
});

it('obterConteudoHtml retorna null quando path_html aponta para objeto inexistente', function () {
    Storage::fake('s3');
    $documento = criarDocumentoHtml([
        'id_documento' => 920006,
        'path_html' => 'documentos-processos/x/920006.html',
    ]);

    $service = new SalvarDocumentoProcessoService();

    expect($service->obterConteudoHtml($documento))->toBeNull();
});

it('obterConteudoHtml retorna null sem coluna e sem path_html', function () {
    Storage::fake('s3');
    $documento = criarDocumentoHtml(['id_documento' => 920007]);

    $service = new SalvarDocumentoProcessoService();

    expect($service->obterConteudoHtml($documento))->toBeNull();
});

it('baixarDocumento NAO re-baixa html quando documento baixado ja tem path_html', function () {
    Storage::fake('s3');
    $documento = criarDocumentoHtml([
        'id_documento' => 920003,
        'status' => ProcessoDocumento::STATUS_BAIXADO,
        'path' => 'documentos-processos/x/920003.pdf',
        'path_html' => 'documentos-processos/x/920003.html',
    ]);
    Storage::disk('s3')->put('documentos-processos/x/920003.pdf', 'pdf-fake');

    $service = Mockery::mock(SalvarDocumentoProcessoService::class)->makePartial();
    $service->shouldNotReceive('downloadHTML');

    $resultado = $service->baixarDocumento($documento);

    expect($resultado->id)->toBe($documento->id);
});

function criarDocumentoParaSalvarConteudo(string $mimetype, int $idDocumento, array $overrides = []): ProcessoDocumento
{
    $processo = Processo::factory()->create([
        'numero_processo' => 'SVCCONT' . getmypid(),
    ]);

    return ProcessoDocumento::create(array_merge([
        'processo_id' => $processo->id,
        'id_documento' => $idDocumento,
        'tipo_documento' => '57',
        'data_hora' => '2024-12-12 10:00:00',
        'descricao' => 'Documento ' . $mimetype,
        'mimetype' => $mimetype,
        'status' => ProcessoDocumento::STATUS_PENDENTE,
        'tentativas_download' => 2,
    ], $overrides));
}

it('salvarConteudo grava o objeto S3 pela chave do mimetype persistido', function (string $mimetype, string $extensao) {
    Storage::fake('s3');
    $documento = criarDocumentoParaSalvarConteudo($mimetype, 940001);
    $numero = $documento->processo->numero_processo;
    $binario = "binario-{$mimetype}";

    $path = (new SalvarDocumentoProcessoService())
        ->salvarConteudo($documento, (object) ['conteudo' => base64_encode($binario)]);

    expect($path)->toBe("documentos-processos/{$numero}/940001.{$extensao}");
    Storage::disk('s3')->assertExists($path);
    expect(Storage::disk('s3')->get($path))->toBe($binario);
})->with([
    'pdf' => ['application/pdf', 'pdf'],
    'audio/mpeg' => ['audio/mpeg', 'mp3'],
    'application/msword' => ['application/msword', 'doc'],
    'mimetype desconhecido' => ['application/x-desconhecido', 'dat'],
]);

it('salvarConteudo de html grava pdf e html e seta path_html', function () {
    Storage::fake('s3');
    $documento = criarDocumentoParaSalvarConteudo('text/html', 940002);
    $numero = $documento->processo->numero_processo;
    $html = '<html><body><h1>Decisao</h1></body></html>';

    $path = (new SalvarDocumentoProcessoService())
        ->salvarConteudo($documento, (object) ['conteudo' => base64_encode($html)]);

    expect($path)->toBe("documentos-processos/{$numero}/940002.pdf");
    Storage::disk('s3')->assertExists("documentos-processos/{$numero}/940002.pdf");
    Storage::disk('s3')->assertExists("documentos-processos/{$numero}/940002.html");
    expect(Storage::disk('s3')->get("documentos-processos/{$numero}/940002.html"))->toBe($html);

    $documento->refresh();
    expect($documento->path_html)->toBe("documentos-processos/{$numero}/940002.html");
});

it('salvarConteudo de audio aceita binario que nao veio em base64', function () {
    Storage::fake('s3');
    $documento = criarDocumentoParaSalvarConteudo('audio/ogg', 940003);
    $numero = $documento->processo->numero_processo;
    $binario = "OggS\x00\x02\xff\xfe";

    $path = (new SalvarDocumentoProcessoService())
        ->salvarConteudo($documento, (object) ['conteudo' => $binario]);

    expect($path)->toBe("documentos-processos/{$numero}/940003.ogg")
        ->and(Storage::disk('s3')->get($path))->toBe($binario);
});

it('salvarConteudo nao marca status nem tentativas', function () {
    Storage::fake('s3');
    $documento = criarDocumentoParaSalvarConteudo('application/pdf', 940004);

    (new SalvarDocumentoProcessoService())
        ->salvarConteudo($documento, (object) ['conteudo' => base64_encode('%PDF')]);

    $documento->refresh();
    expect($documento->status)->toBe(ProcessoDocumento::STATUS_PENDENTE)
        ->and($documento->tentativas_download)->toBe(2)
        ->and($documento->getRawOriginal('path'))->toBeNull();
});

it('salvarConteudo recusa video, que segue o download por documento', function (string $mimetype) {
    Storage::fake('s3');
    $documento = criarDocumentoParaSalvarConteudo($mimetype, 940005);

    expect(fn () => (new SalvarDocumentoProcessoService())
        ->salvarConteudo($documento, (object) ['conteudo' => base64_encode('ftyp')]))
        ->toThrow(InvalidArgumentException::class);

    expect(Storage::disk('s3')->allFiles())->toBe([]);
})->with(['video/mp4', 'video/quicktime']);

it('salvarConteudo lanca excecao sem emitir Log::error quando a gravacao falha', function (string $mimetype, string $mensagem) {
    Storage::fake('s3');
    Log::spy();
    $documento = criarDocumentoParaSalvarConteudo($mimetype, 940006);

    expect(fn () => (new SalvarDocumentoProcessoService())
        ->salvarConteudo($documento, (object) ['conteudo' => '']))
        ->toThrow(Exception::class, $mensagem);

    Log::shouldNotHaveReceived('error');
})->with([
    'html' => ['text/html', 'Conteúdo do documento HTML está vazio'],
    'pdf' => ['application/pdf', 'Conteúdo do PDF está vazio'],
    'audio' => ['audio/mpeg', 'Conteúdo do áudio está vazio'],
    'outros' => ['application/msword', 'Conteúdo do documento está vazio'],
]);

it('salvarConteudo grava sem emitir Log::error', function (string $mimetype) {
    Storage::fake('s3');
    Log::spy();
    $documento = criarDocumentoParaSalvarConteudo($mimetype, 940007);

    (new SalvarDocumentoProcessoService())
        ->salvarConteudo($documento, (object) ['conteudo' => base64_encode('<p>conteudo</p>')]);

    Log::shouldNotHaveReceived('error');
})->with(['text/html', 'application/pdf', 'audio/mpeg', 'application/msword']);

it('download* consultam o MNI e gravam pelas mesmas chaves de salvarConteudo', function (string $metodo, string $mimetype, string $extensao) {
    Storage::fake('s3');
    $documento = criarDocumentoParaSalvarConteudo($mimetype, 940008);
    $numero = $documento->processo->numero_processo;

    $service = Mockery::mock(SalvarDocumentoProcessoService::class)->makePartial();
    $service->shouldReceive('consultarDocumento')
        ->once()
        ->with($documento, 'login-pje', 'senha-pje')
        ->andReturn((object) ['conteudo' => base64_encode('conteudo')]);

    $path = $service->{$metodo}($documento, 'login-pje', 'senha-pje');

    expect($path)->toBe("documentos-processos/{$numero}/940008.{$extensao}");
    Storage::disk('s3')->assertExists($path);
})->with([
    'downloadHTML' => ['downloadHTML', 'text/html', 'pdf'],
    'downloadPDF' => ['downloadPDF', 'application/pdf', 'pdf'],
    'downloadAudio' => ['downloadAudio', 'audio/mpeg', 'mp3'],
    'downloadOutros' => ['downloadOutros', 'application/msword', 'doc'],
]);

it('download* mantem o prefixo da mensagem de erro do fluxo sincrono', function (string $metodo, string $mimetype, string $mensagem) {
    Storage::fake('s3');
    $documento = criarDocumentoParaSalvarConteudo($mimetype, 940009);

    $service = Mockery::mock(SalvarDocumentoProcessoService::class)->makePartial();
    $service->shouldReceive('consultarDocumento')->once()->andReturn((object) ['conteudo' => '']);

    expect(fn () => $service->{$metodo}($documento))->toThrow(Exception::class, $mensagem);
})->with([
    'downloadHTML' => ['downloadHTML', 'text/html', 'Erro ao processar documento HTML: Conteúdo do documento HTML está vazio'],
    'downloadPDF' => ['downloadPDF', 'application/pdf', 'Erro ao processar documento PDF: Conteúdo do PDF está vazio'],
    'downloadAudio' => ['downloadAudio', 'audio/mpeg', 'Erro ao processar documento de áudio: Conteúdo do áudio está vazio'],
    'downloadOutros' => ['downloadOutros', 'application/msword', 'Erro ao processar documento: Conteúdo do documento está vazio'],
]);

it('download* prefixam a falha da consulta ao MNI', function () {
    $documento = criarDocumentoParaSalvarConteudo('application/pdf', 940010);

    $service = Mockery::mock(SalvarDocumentoProcessoService::class)->makePartial();
    $service->shouldReceive('consultarDocumento')
        ->once()
        ->andThrow(new RuntimeException('Falha de transporte'));

    expect(fn () => $service->downloadPDF($documento))
        ->toThrow(Exception::class, 'Erro ao processar documento PDF: Falha de transporte');
});

it('marcarComoErro grava erro, incrementa a tentativa e emite exatamente 1 Log::error', function () {
    Log::spy();
    $documento = criarDocumentoParaSalvarConteudo('application/pdf', 940011);

    (new SalvarDocumentoProcessoService())->marcarComoErro($documento, 'Documento não retornado pelo MNI');

    $documento->refresh();
    expect($documento->status)->toBe(ProcessoDocumento::STATUS_ERRO)
        ->and($documento->tentativas_download)->toBe(3);

    Log::shouldHaveReceived('error')->once();
    Log::shouldHaveReceived('error')->withArgs(
        fn ($mensagem, $contexto) => str_starts_with($mensagem, "[ProcessoDocumento:{$documento->id}]")
            && $contexto === ['id_documento' => 940011, 'motivo' => 'Documento não retornado pelo MNI']
    );
});

it('marcarComoErro sem incrementar preserva tentativas_download', function () {
    Log::spy();
    $documento = criarDocumentoParaSalvarConteudo('application/pdf', 940012, [
        'status' => ProcessoDocumento::STATUS_BAIXADO,
    ]);

    (new SalvarDocumentoProcessoService())->marcarComoErro($documento, 'Falha definitiva do lote', false);

    $documento->refresh();
    expect($documento->status)->toBe(ProcessoDocumento::STATUS_ERRO)
        ->and($documento->tentativas_download)->toBe(2);

    Log::shouldHaveReceived('error')->once();
});

it('marcarComoErro conta a partir de zero quando tentativas_download esta nulo em memoria', function () {
    Log::spy();
    $documento = criarDocumentoParaSalvarConteudo('application/pdf', 940013);
    $documento->tentativas_download = null;

    (new SalvarDocumentoProcessoService())->marcarComoErro($documento, 'motivo');

    expect($documento->refresh()->tentativas_download)->toBe(1);
});

// Sinal do tribunal no download -------------------------------------------------

const LOGIN_RECUSADO_SALVAR_DOCUMENTO = 'Erro ao realizar login via MNI. exception invoking: loginFailed';
const LOGIN_PADRONIZADO_SALVAR_DOCUMENTO = 'Erro ao realizar login via MNI. Verifique suas credenciais.';
const PROCESSO_NAO_ENCONTRADO_SALVAR_DOCUMENTO = 'Processo de número 99999901120268030001 não encontrado!';

function falhaSalvarDocumento(Closure $acao): ?Throwable
{
    try {
        $acao();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

// MNIException não preenche getMessage(), então a mensagem dos wrappers fica
// só com o prefixo, como no commit base b9f0028.
it('download* preservam a exceção classificada como previous sem mudar a mensagem', function (string $metodo, string $mimetype, string $mensagem, string $classe, string $texto) {
    $documento = criarDocumentoParaSalvarConteudo($mimetype, 940014);
    $classificada = new $classe($texto, 500);

    $service = Mockery::mock(SalvarDocumentoProcessoService::class)->makePartial();
    $service->shouldReceive('consultarDocumento')
        ->once()
        ->with($documento, 'login-pje', 'senha-pje')
        ->andThrow($classificada);

    $capturada = falhaSalvarDocumento(fn () => $service->{$metodo}($documento, 'login-pje', 'senha-pje'));

    expect(get_class($capturada))->toBe(Exception::class)
        ->and($capturada->getMessage())->toBe($mensagem)
        ->and($capturada->getCode())->toBe(0)
        ->and($capturada->getPrevious())->toBe($classificada);
})->with([
    'downloadHTML' => ['downloadHTML', 'text/html', 'Erro ao processar documento HTML: '],
    'downloadPDF' => ['downloadPDF', 'application/pdf', 'Erro ao processar documento PDF: '],
    'downloadAudio' => ['downloadAudio', 'audio/mpeg', 'Erro ao processar documento de áudio: '],
    'downloadOutros' => ['downloadOutros', 'application/msword', 'Erro ao processar documento: '],
])->with([
    'credencial recusada' => [CredencialPjeRecusadaException::class, LOGIN_RECUSADO_SALVAR_DOCUMENTO],
    'processo não encontrado' => [ProcessoNaoEncontradoNoTribunalException::class, PROCESSO_NAO_ENCONTRADO_SALVAR_DOCUMENTO],
]);

it('consultarDocumento relança a exceção classificada sem re-embrulhar', function (?string $login, ?string $senha, string $texto, string $classe, string $erro) {
    Log::spy();
    [, $documentos] = criarDocumentosLote();
    $documento = $documentos->first();
    $registro = simularIntegracaoMni(fn () => (object) ['sucesso' => false, 'mensagem' => $texto]);

    $capturada = falhaSalvarDocumento(fn () => (new SalvarDocumentoProcessoService())
        ->consultarDocumento($documento, $login, $senha));

    expect(get_class($capturada))->toBe($classe)
        ->and($capturada->getError())->toBe($erro)
        ->and($capturada->getCode())->toBe(500)
        ->and($registro->requisicoes)->toHaveCount(1)
        ->and($registro->requisicoes[0]['parametros']['documento'])->toBe($documento->id_documento);

    Log::shouldHaveReceived('error')->once();
    Log::shouldHaveReceived('error')->with('Erro MNI ao consultar documento', [
        'documento_id' => $documento->id_documento,
        'error' => $erro,
        'code' => 500,
    ]);
})->with([
    'processo não encontrado com o par informado' => ['{LOGIN}', '{SENHA}', PROCESSO_NAO_ENCONTRADO_SALVAR_DOCUMENTO, ProcessoNaoEncontradoNoTribunalException::class, PROCESSO_NAO_ENCONTRADO_SALVAR_DOCUMENTO],
    'processo não encontrado com a credencial do tribunal' => [null, null, PROCESSO_NAO_ENCONTRADO_SALVAR_DOCUMENTO, ProcessoNaoEncontradoNoTribunalException::class, PROCESSO_NAO_ENCONTRADO_SALVAR_DOCUMENTO],
    'credencial recusada com o par informado' => ['{LOGIN}', '{SENHA}', LOGIN_RECUSADO_SALVAR_DOCUMENTO, CredencialPjeRecusadaException::class, LOGIN_PADRONIZADO_SALVAR_DOCUMENTO],
]);

it('consultarDocumento continua re-embrulhando a MNIException comum', function () {
    [, $documentos] = criarDocumentosLote();
    simularIntegracaoMni(fn () => (object) ['sucesso' => false, 'mensagem' => 'MNI indisponível']);

    $capturada = falhaSalvarDocumento(fn () => (new SalvarDocumentoProcessoService())
        ->consultarDocumento($documentos->first(), '{LOGIN}', '{SENHA}'));

    expect(get_class($capturada))->toBe(MNIException::class)
        ->and($capturada->getError())->toBe('MNI indisponível')
        ->and($capturada->getCode())->toBe(500);
});

it('baixarDocumento de HTML legado mantém a mensagem e não leva a exceção classificada na cadeia', function (string $classe, string $texto) {
    Storage::fake('s3');
    $documento = criarDocumentoHtml([
        'id_documento' => 920008,
        'status' => ProcessoDocumento::STATUS_BAIXADO,
        'path' => 'documentos-processos/x/920008.pdf',
    ]);
    Storage::disk('s3')->put('documentos-processos/x/920008.pdf', 'pdf-fake');
    $falha = new Exception('Erro ao processar documento HTML: ', 0, new $classe($texto, 500));

    $service = Mockery::mock(SalvarDocumentoProcessoService::class)->makePartial();
    $service->shouldReceive('downloadHTML')->once()->with($documento)->andThrow($falha);

    $capturada = falhaSalvarDocumento(fn () => $service->baixarDocumento($documento, 'login-pje', 'senha-pje'));

    expect(get_class($capturada))->toBe(Exception::class)
        ->and($capturada->getMessage())->toBe('Erro ao processar documento HTML: ')
        ->and($capturada->getPrevious())->toBeNull();
})->with([
    'credencial recusada' => [CredencialPjeRecusadaException::class, LOGIN_RECUSADO_SALVAR_DOCUMENTO],
    'processo não encontrado' => [ProcessoNaoEncontradoNoTribunalException::class, PROCESSO_NAO_ENCONTRADO_SALVAR_DOCUMENTO],
]);

it('baixarDocumento de HTML legado reconsulta com a credencial do tribunal e corta a cadeia', function (string $texto) {
    Storage::fake('s3');
    [, $documentos] = criarDocumentosLote([[
        'mimetype' => 'text/html',
        'status' => ProcessoDocumento::STATUS_BAIXADO,
        'path' => 'documentos-processos/x/legado.pdf',
    ]]);
    Storage::disk('s3')->put('documentos-processos/x/legado.pdf', 'pdf-fake');
    $registro = simularIntegracaoMni(fn () => (object) ['sucesso' => false, 'mensagem' => $texto]);

    $capturada = falhaSalvarDocumento(fn () => (new SalvarDocumentoProcessoService())
        ->baixarDocumento($documentos->first(), 'login-pje', 'senha-pje'));

    expect(get_class($capturada))->toBe(Exception::class)
        ->and($capturada->getMessage())->toBe('Erro ao processar documento HTML: ')
        ->and($capturada->getPrevious())->toBeNull()
        ->and($registro->requisicoes)->toHaveCount(1)
        ->and($registro->requisicoes[0]['parametros']['idConsultante'])->toBe('login-do-tribunal');
})->with([
    'loginFailed' => LOGIN_RECUSADO_SALVAR_DOCUMENTO,
    'processo não encontrado' => PROCESSO_NAO_ENCONTRADO_SALVAR_DOCUMENTO,
]);
