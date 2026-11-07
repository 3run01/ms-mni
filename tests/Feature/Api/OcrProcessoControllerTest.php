<?php

use App\Exceptions\ConfiguracaoOcrIncompletaException;
use App\Exceptions\MNIException;
use App\Http\Controllers\Api\OcrProcessoController;
use App\Http\Middleware\ForcarRespostaJson;
use App\Http\Middleware\InjectCredenciaisPjePadrao;
use App\Http\Middleware\ValidateApiToken;
use App\Jobs\BaixarDocumentoParaOcrJob;
use App\Jobs\EnviarAvisoOcrJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\ProcessoOcrAssinante;
use App\Models\ProcessoOcrProcessamento;
use App\Models\Tribunal;
use App\Services\Callback\CallbackNotifier;
use App\Services\Ocr\ProcessamentoOcrService;
use App\Services\Ocr\SolicitarOcrProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/*
 * Rota POST /api/processo/ocr (T20; RF-02 AC a e b, RF-03, RF-05, RF-06 AC d,
 * RF-17, CT-01, D-02, D-05, D-06): só com X-API-Token, fora do par padrão,
 * validação do corpo `SolicitacaoDeOcr` e o status de cada falha da consulta
 * síncrona. As regras do pedido (trava, carona, abertura) estão em
 * SolicitarOcrProcessoServiceTest; aqui elas são vistas pela camada HTTP.
 */

uses(DatabaseTransactions::class);

const NUMERO_OCR_API = '12345674220268260100';
const NUMERO_COM_MASCARA_OCR_API = '1234567-42.2026.8.26.0100';
const TOKEN_API_OCR_API = 'tk-ocr-processo-api';
const LOGIN_OCR_API = 'login-membro-3d8a';
const SENHA_OCR_API = 'senha-membro-6e2b';
const LOGIN_PADRAO_OCR_API = 'login-padrao-ocr-api';
const SENHA_PADRAO_OCR_API = 'senha-padrao-ocr-api';
const CALLBACK_URL_OCR_API = 'https://sistema.example/mni/processamentos/9f1c/progresso?signature=a1b2';
const CALLBACK_TOKEN_OCR_API = 'tok-callback-ocr-api-5c1';
const CALLBACK_DEV_OCR_API = 'http://host.docker.internal:8006/mni/aviso?assinatura=x';
const URL_LINHA_OCR_API = 'https://tjap-1grau.example/mni/consultar-processo';
const RECUSA_OCR_API = 'Erro ao realizar login via MNI. exception invoking: loginFailed';
const MENSAGEM_401_OCR_API = 'Token inválido ou não fornecido';
const MENSAGEM_CALLBACK_URL_OCR_API = 'O callback url deve ser uma URL https válida e não pode apontar para IP interno.';
const AUSENTE_OCR_API = '__campo_ausente__';

beforeEach(function () {
    Queue::fake();

    config()->set('services.n8n_ocr.url', 'https://n8n.example/webhook/juridico-ocr');
    config()->set('services.n8n_ocr.token', 'token-workflow-ocr-api');
    config()->set('services.n8n_ocr.espera_da_trava_segundos', 45);

    // O callback padrão dos testes passa sem DNS: fora de `production`, o
    // host liberado é aceito sem resolver (RF-17).
    config()->set('pje.callback.hosts_liberados', ['sistema.example']);

    // O par padrão existe, mas a rota de OCR nunca o usa (RF-02).
    definirCredenciaisPadrao(LOGIN_PADRAO_OCR_API, SENHA_PADRAO_OCR_API);

    $this->apiToken = criarTokenApi(TOKEN_API_OCR_API);
    $this->headers = ['X-API-Token' => TOKEN_API_OCR_API];

    $this->tribunal = Tribunal::factory()->create([
        'sigla' => 'TJAP',
        'grau' => '1',
        'login' => 'login-do-tribunal',
        'password' => Crypt::encrypt('{SENHA}'),
        'url_webservice_mni_consultar_processo' => URL_LINHA_OCR_API,
        'ativo' => true,
    ]);
});

function corpoOcrApi(Tribunal $tribunal, array $extra = []): array
{
    return array_merge([
        'numero_processo' => NUMERO_OCR_API,
        'tribunal_id' => $tribunal->id,
        'login_pje' => LOGIN_OCR_API,
        'senha_pje' => SENHA_OCR_API,
        'callback_url' => CALLBACK_URL_OCR_API,
        'callback_token' => CALLBACK_TOKEN_OCR_API,
    ], $extra);
}

function pedirOcrApi(array $corpo, ?array $headers = null): TestResponse
{
    return test()->postJson('/api/processo/ocr', $corpo, $headers ?? test()->headers);
}

/**
 * consultarProcesso com sucesso, cru como o MNI devolve, com um documento por
 * item `[id_documento => mimetype]`.
 *
 * @param  array<int, string>  $documentos
 */
function mniOcrApi(array $documentos = []): stdClass
{
    $crus = [];
    foreach ($documentos as $idDocumento => $mimetype) {
        $crus[] = documentoMniFake($idDocumento, ['mimetype' => $mimetype]);
    }

    return simularIntegracaoMni(fn () => (object) [
        'sucesso' => true,
        'processo' => (object) [
            'dadosBasicos' => (object) [
                'numero' => NUMERO_OCR_API,
                'codigoLocalidade' => '1',
                'classeProcessual' => '7',
                'nivelSigilo' => 0,
                'valorCausa' => 1500,
            ],
            'movimento' => [],
            'documento' => $crus,
        ],
    ]);
}

/**
 * Linhas das 3 tabelas do OCR. O banco de teste pode ter dados de
 * desenvolvimento: compare o antes e o depois.
 *
 * @return array{processamentos: int, assinantes: int, avisos: int}
 */
function linhasOcrApi(): array
{
    return [
        'processamentos' => DB::table('processo_ocr_processamentos')->count(),
        'assinantes' => DB::table('processo_ocr_assinantes')->count(),
        'avisos' => DB::table('processo_ocr_avisos')->count(),
    ];
}

/**
 * O corpo e os headers da resposta nunca trazem o par nem o callback_token
 * (RNF-01).
 */
function semSegredosOcrApi(TestResponse $resposta): void
{
    $texto = $resposta->getContent().json_encode($resposta->headers->all());

    foreach ([LOGIN_OCR_API, SENHA_OCR_API, CALLBACK_TOKEN_OCR_API, LOGIN_PADRAO_OCR_API, SENHA_PADRAO_OCR_API] as $segredo) {
        expect($texto)->not->toContain($segredo);
    }
}

/**
 * Os logs da requisição, na ordem, para conferir o que foi registrado.
 *
 * @return ArrayObject<int, MessageLogged>
 */
function capturarLogsOcrApi(): ArrayObject
{
    $logs = new ArrayObject();
    Event::listen(MessageLogged::class, fn (MessageLogged $evento) => $logs->append($evento));

    return $logs;
}

it('registra a rota só com ValidateApiToken e ForcarRespostaJson, fora de InjectCredenciaisPjePadrao', function () {
    $rota = Route::getRoutes()->match(request()->create('/api/processo/ocr', 'POST'));
    $middlewares = $rota->gatherMiddleware();

    expect($rota->getActionName())->toBe(OcrProcessoController::class.'@store')
        ->and($middlewares)->toContain(ValidateApiToken::class)
        ->and($middlewares)->toContain(ForcarRespostaJson::class)
        ->and($middlewares)->not->toContain(InjectCredenciaisPjePadrao::class);
});

it('sem token válido responde 401 com o corpo exato, 0 SOAP e 0 jobs', function (?string $token) {
    $mni = mniOcrApi([9500001 => 'application/pdf']);
    $antes = linhasOcrApi();

    $resposta = pedirOcrApi(corpoOcrApi($this->tribunal), $token === null ? [] : ['X-API-Token' => $token]);

    $resposta->assertStatus(401)->assertExactJson(['message' => MENSAGEM_401_OCR_API]);
    semSegredosOcrApi($resposta);

    expect($mni->requisicoes)->toHaveCount(0)
        ->and(linhasOcrApi())->toBe($antes);
    Queue::assertNothingPushed();
})->with([
    'sem header' => [null],
    'token desconhecido' => ['tk-que-nao-existe'],
]);

it('com o par padrão configurado e o corpo sem o par do pedido, responde 422 sem usar o par padrão: 0 SOAP e 0 jobs', function (array $semCampos, array $chavesComErro) {
    $mni = mniOcrApi([9500002 => 'application/pdf']);
    $antes = linhasOcrApi();

    $corpo = array_diff_key(corpoOcrApi($this->tribunal), array_flip($semCampos));
    $resposta = pedirOcrApi($corpo);

    $resposta->assertStatus(422)->assertJsonValidationErrors($chavesComErro);
    semSegredosOcrApi($resposta);

    expect($mni->requisicoes)->toHaveCount(0)
        ->and(linhasOcrApi())->toBe($antes);
    Queue::assertNothingPushed();
})->with([
    'sem senha_pje' => [['senha_pje'], ['senha_pje']],
    'sem login_pje' => [['login_pje'], ['login_pje']],
    'sem o par' => [['login_pje', 'senha_pje'], ['login_pje', 'senha_pje']],
]);

it('campo ausente ou inválido responde 422 com a chave em errors, 0 SOAP, 0 jobs, 0 linhas do OCR e sem o par nem o callback_token no corpo', function (string $campo, mixed $valor) {
    $mni = mniOcrApi([9500003 => 'application/pdf']);
    $antes = linhasOcrApi();

    $corpo = corpoOcrApi($this->tribunal);

    // O tribunal inativo é criado só aqui, depois do beforeEach.
    if ($valor instanceof Closure) {
        $valor = $valor();
    }

    if ($valor === AUSENTE_OCR_API) {
        unset($corpo[$campo]);
    } else {
        $corpo[$campo] = $valor;
    }

    $resposta = pedirOcrApi($corpo);

    $resposta->assertStatus(422)
        ->assertJsonStructure(['message', 'errors'])
        ->assertJsonValidationErrors([$campo]);
    semSegredosOcrApi($resposta);

    expect($mni->requisicoes)->toHaveCount(0)
        ->and(linhasOcrApi())->toBe($antes);
    Queue::assertNothingPushed();
})->with([
    'numero_processo ausente' => ['numero_processo', AUSENTE_OCR_API],
    'numero_processo curto' => ['numero_processo', '1234567422026826010'],
    'numero_processo com letra' => ['numero_processo', '1234567422026826010X'],
    'numero_processo longo' => ['numero_processo', '123456742202682601001'],
    'numero_processo lista' => ['numero_processo', [NUMERO_OCR_API]],
    'tribunal_id ausente' => ['tribunal_id', AUSENTE_OCR_API],
    'tribunal_id zero' => ['tribunal_id', 0],
    'tribunal_id texto' => ['tribunal_id', 'abc'],
    'tribunal_id inexistente' => ['tribunal_id', 2_000_000_000],
    'tribunal_id inativo' => ['tribunal_id', fn () => Tribunal::factory()->create(['ativo' => false])->id],
    'login_pje ausente' => ['login_pje', AUSENTE_OCR_API],
    'login_pje vazio' => ['login_pje', ''],
    'login_pje só espaços' => ['login_pje', '   '],
    'login_pje lista' => ['login_pje', ['x']],
    'senha_pje ausente' => ['senha_pje', AUSENTE_OCR_API],
    'senha_pje só espaços' => ['senha_pje', '   '],
    'senha_pje número' => ['senha_pje', 12345],
    'callback_url ausente' => ['callback_url', AUSENTE_OCR_API],
    'callback_url http fora da lista' => ['callback_url', 'http://10.0.0.5/aviso'],
    'callback_url https com IP interno' => ['callback_url', 'https://10.0.0.5/aviso'],
    'callback_url localhost' => ['callback_url', 'https://localhost/aviso'],
    'callback_url sem esquema' => ['callback_url', 'sistema.example/aviso'],
    'callback_url com mais de 2048 caracteres (D-05)' => ['callback_url', 'https://sistema.example/'.str_repeat('a', 2025)],
    'callback_token ausente' => ['callback_token', AUSENTE_OCR_API],
    'callback_token vazio' => ['callback_token', ''],
    'callback_token com 256 caracteres' => ['callback_token', str_repeat('t', 256)],
]);

it('aceita callback_url com exatamente 2048 caracteres (D-05)', function () {
    mniOcrApi([9500004 => 'application/pdf']);

    $url = 'https://sistema.example/'.str_repeat('a', 2048 - strlen('https://sistema.example/'));
    expect(strlen($url))->toBe(2048);

    pedirOcrApi(corpoOcrApi($this->tribunal, ['callback_url' => $url]))->assertStatus(202);
});

it('responde o 422 em JSON mesmo sem Accept: application/json', function () {
    $mni = mniOcrApi();

    $resposta = $this->post('/api/processo/ocr', corpoOcrApi($this->tribunal, ['senha_pje' => null]), $this->headers + ['Accept' => '*/*']);

    $resposta->assertStatus(422)
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonValidationErrors(['senha_pje']);

    expect($mni->requisicoes)->toHaveCount(0);
});

it('pedido válido responde 202 ProcessamentoDeOcr com exatamente as chaves do contrato, normaliza o número com máscara e consulta o MNI com o par do pedido', function () {
    $mni = mniOcrApi([
        9500101 => 'application/pdf',
        9500102 => 'application/pdf',
        9500103 => 'application/pdf',
        9500104 => 'text/html',
        9500105 => 'image/png',
        9500106 => 'video/mp4',
        9500107 => 'audio/mpeg',
    ]);

    $resposta = pedirOcrApi(corpoOcrApi($this->tribunal, ['numero_processo' => NUMERO_COM_MASCARA_OCR_API]));

    $resposta->assertStatus(202)->assertExactJson([
        'numero_processo' => NUMERO_OCR_API,
        'tribunal_id' => $this->tribunal->id,
        'total' => 5,
        'processados' => 0,
        'com_erro' => 0,
        'ignorados' => 2,
        'situacao' => 'em_andamento',
    ]);
    semSegredosOcrApi($resposta);

    // RF-02: 1 consulta, com o par do pedido, nunca o par padrão.
    expect($mni->requisicoes)->toHaveCount(1)
        ->and($mni->requisicoes[0]['parametros'])->toMatchArray([
            'idConsultante' => LOGIN_OCR_API,
            'senhaConsultante' => SENHA_OCR_API,
        ]);

    Queue::assertPushed(BaixarDocumentoParaOcrJob::class, 5);

    // O assinante dono fica com o token da API que pediu e só o callback.
    $processo = Processo::where('tribunal_id', $this->tribunal->id)->where('numero_processo', NUMERO_OCR_API)->sole();
    $processamento = ProcessoOcrProcessamento::where('processo_id', $processo->id)->sole();
    $dono = ProcessoOcrAssinante::where('processamento_id', $processamento->id)->sole();

    expect($dono->dono)->toBeTrue()
        ->and($dono->api_token_id)->toBe($this->apiToken->id)
        ->and($dono->callback_url)->toBe(CALLBACK_URL_OCR_API)
        ->and($dono->callback_token)->toBe(CALLBACK_TOKEN_OCR_API);
});

it('o 2º pedido do mesmo processo e linha, de outro token, entra como carona: 202 com a contagem atual e 0 SOAP', function () {
    $mni = mniOcrApi([9500201 => 'application/pdf', 9500202 => 'image/jpeg']);

    $primeiro = pedirOcrApi(corpoOcrApi($this->tribunal))->assertStatus(202);

    criarTokenApi('tk-ocr-processo-api-carona');
    $segundo = pedirOcrApi(
        corpoOcrApi($this->tribunal, [
            'login_pje' => 'login-carona-ocr-api',
            'senha_pje' => 'senha-carona-ocr-api',
            'callback_url' => 'https://sistema.example/mni/processamentos/carona/progresso',
            'callback_token' => 'tok-carona-ocr-api',
        ]),
        ['X-API-Token' => 'tk-ocr-processo-api-carona'],
    )->assertStatus(202);

    expect($segundo->json())->toBe($primeiro->json())
        ->and(collect($mni->requisicoes)->where('operacao', 'consultarProcesso'))->toHaveCount(1);

    Queue::assertPushed(BaixarDocumentoParaOcrJob::class, 2);
});

it('sem documento a ler responde 202 concluido, sem processamento e sem job', function () {
    $mni = mniOcrApi([9500301 => 'video/mp4', 9500302 => 'audio/mpeg']);
    $antes = linhasOcrApi();

    pedirOcrApi(corpoOcrApi($this->tribunal))
        ->assertStatus(202)
        ->assertExactJson([
            'numero_processo' => NUMERO_OCR_API,
            'tribunal_id' => $this->tribunal->id,
            'total' => 0,
            'processados' => 0,
            'com_erro' => 0,
            'ignorados' => 2,
            'situacao' => 'concluido',
        ]);

    expect($mni->requisicoes)->toHaveCount(1)
        ->and(linhasOcrApi())->toBe($antes);
    Queue::assertNothingPushed();
});

it('a falha da consulta responde com o status e o corpo exatos, sem error, line, file nem o par, com 1 SOAP, 0 processamentos e 0 jobs', function (Closure $responder, int $status, array $corpo) {
    // Com debug ligado, nada de trace nem de texto do tribunal no corpo.
    config()->set('app.debug', true);

    $mni = simularIntegracaoMni($responder);
    $antes = linhasOcrApi();

    $resposta = pedirOcrApi(corpoOcrApi($this->tribunal));

    $resposta->assertStatus($status)
        ->assertExactJson($corpo)
        ->assertJsonMissingPath('error')
        ->assertJsonMissingPath('line')
        ->assertJsonMissingPath('file')
        ->assertJsonMissingPath('trace')
        ->assertJsonMissingPath('exception');
    semSegredosOcrApi($resposta);

    expect($resposta->getContent())->not->toContain('loginFailed')
        ->and($resposta->getContent())->not->toContain('indisponível')
        ->and($mni->requisicoes)->toHaveCount(1)
        ->and(linhasOcrApi())->toBe($antes);
    Queue::assertNothingPushed();
})->with([
    'recusa do par (sucesso = false)' => [
        fn () => (object) ['sucesso' => false, 'mensagem' => RECUSA_OCR_API],
        403,
        ['codigo' => 'credencial_pje_recusada', 'message' => 'Credencial PJe recusada pelo tribunal'],
    ],
    'recusa do par (SoapFault)' => [
        fn () => throw new SoapFault('Server', RECUSA_OCR_API),
        403,
        ['codigo' => 'credencial_pje_recusada', 'message' => 'Credencial PJe recusada pelo tribunal'],
    ],
    'processo inexistente' => [
        fn () => (object) ['sucesso' => false, 'mensagem' => 'Processo de número '.NUMERO_OCR_API.' não encontrado!'],
        404,
        ['codigo' => 'processo_nao_encontrado', 'message' => 'Processo não encontrado no tribunal'],
    ],
    'outra falha do MNI' => [
        fn () => (object) ['sucesso' => false, 'mensagem' => 'Serviço temporariamente indisponível'],
        500,
        ['message' => 'Não foi possível processar o pedido de OCR'],
    ],
    'exceção com o par no texto' => [
        fn () => throw new RuntimeException('Falha de rede com idConsultante='.LOGIN_OCR_API.' senha='.SENHA_OCR_API),
        500,
        ['message' => 'Não foi possível processar o pedido de OCR'],
    ],
]);

it('na outra falha, registra Log::error só com a linha, o número e a classe da exceção', function () {
    $logs = capturarLogsOcrApi();
    simularIntegracaoMni(fn () => throw new RuntimeException('texto do tribunal com '.LOGIN_OCR_API.' e '.SENHA_OCR_API));

    pedirOcrApi(corpoOcrApi($this->tribunal))->assertStatus(500);

    $erros = collect($logs)->filter(fn (MessageLogged $log) => $log->level === 'error')->values();

    expect($erros)->toHaveCount(1)
        ->and($erros[0]->message)->toBe('Pedido de OCR falhou')
        ->and(array_keys($erros[0]->context))->toBe(['tribunal_id', 'numero_processo', 'excecao'])
        ->and($erros[0]->context['tribunal_id'])->toBe($this->tribunal->id)
        ->and($erros[0]->context['numero_processo'])->toBe(NUMERO_OCR_API)
        ->and(in_array($erros[0]->context['excecao'], [RuntimeException::class, MNIException::class], true))->toBeTrue();

    $textoDosLogs = collect($logs)->map(fn (MessageLogged $log) => $log->message.json_encode($log->context))->implode("\n");

    foreach ([LOGIN_OCR_API, SENHA_OCR_API, CALLBACK_TOKEN_OCR_API, 'texto do tribunal'] as $segredo) {
        expect($textoDosLogs)->not->toContain($segredo);
    }
});

it('sem a configuração do workflow responde 500 genérico, antes de qualquer SOAP', function () {
    config()->set('services.n8n_ocr.url', null);
    $logs = capturarLogsOcrApi();
    $mni = mniOcrApi([9500401 => 'application/pdf']);
    $antes = linhasOcrApi();

    pedirOcrApi(corpoOcrApi($this->tribunal))
        ->assertStatus(500)
        ->assertExactJson(['message' => 'Não foi possível processar o pedido de OCR']);

    expect($mni->requisicoes)->toHaveCount(0)
        ->and(linhasOcrApi())->toBe($antes)
        ->and(collect($logs)->first(fn (MessageLogged $log) => $log->level === 'error')?->context['excecao'])
        ->toBe(ConfiguracaoOcrIncompletaException::class);
    Queue::assertNothingPushed();
});

it('com W = 1 e a decisão presa por outro pedido, responde 500 em menos de 2 s, sem SOAP, assinante nem job', function () {
    config()->set('services.n8n_ocr.espera_da_trava_segundos', 1);
    config()->set('app.debug', true);

    $mni = mniOcrApi([9500501 => 'application/pdf']);
    $antes = linhasOcrApi();

    $outroPedido = Cache::lock(SolicitarOcrProcessoService::chaveDaTrava($this->tribunal->id, NUMERO_OCR_API), 600);
    expect($outroPedido->get())->toBeTrue();

    try {
        $inicio = microtime(true);
        $resposta = pedirOcrApi(corpoOcrApi($this->tribunal));
        $duracao = microtime(true) - $inicio;
    } finally {
        $outroPedido->release();
    }

    $resposta->assertStatus(500)
        ->assertExactJson(['message' => 'Pedido de OCR em andamento para este processo; tente de novo'])
        ->assertJsonMissingPath('error')
        ->assertJsonMissingPath('line')
        ->assertJsonMissingPath('file');
    semSegredosOcrApi($resposta);

    expect($duracao)->toBeLessThan(2.0)
        ->and($mni->requisicoes)->toHaveCount(0)
        ->and(linhasOcrApi())->toBe($antes);
    Queue::assertNothingPushed();
});

it('RF-17: em local, com host.docker.internal na lista, aceita o callback http e o aviso vai para essa URL', function () {
    config()->set('app.env', 'local');
    config()->set('pje.callback.hosts_liberados', ['host.docker.internal']);
    Http::fake(['*' => Http::response('', 200)]);

    mniOcrApi([9500601 => 'application/pdf']);

    pedirOcrApi(corpoOcrApi($this->tribunal, ['callback_url' => CALLBACK_DEV_OCR_API]))
        ->assertStatus(202)
        ->assertJsonPath('situacao', 'em_andamento');

    $processo = Processo::where('tribunal_id', $this->tribunal->id)->where('numero_processo', NUMERO_OCR_API)->sole();
    $documento = ProcessoDocumento::where('processo_id', $processo->id)->sole();

    // O documento vai ao workflow e volta lido: gera os avisos do assinante.
    $estados = app(ProcessamentoOcrService::class);
    expect($estados->registrarEnvio($documento, 'job-dev-ocr-api'))->toBeTrue();
    $estados->aplicarRetorno($documento->fresh(), true);

    $avisos = Queue::pushed(EnviarAvisoOcrJob::class);
    expect($avisos)->not->toBeEmpty();

    foreach ($avisos as $aviso) {
        $aviso->withFakeQueueInteractions()->handle(app(CallbackNotifier::class));
    }

    $requisicoes = Http::recorded()->map(fn (array $par) => $par[0]);

    expect($requisicoes)->toHaveCount($avisos->count());

    $requisicoes->each(function (Request $requisicao) {
        expect($requisicao->url())->toBe(CALLBACK_DEV_OCR_API)
            ->and($requisicao->method())->toBe('POST')
            ->and($requisicao->header('X-API-Token'))->toBe([CALLBACK_TOKEN_OCR_API]);
    });
});

it('RF-17: o callback http de host liberado responde 422 em production, com a lista vazia e com host fora da lista', function (string $ambiente, array $hosts, string $callbackUrl) {
    config()->set('app.env', $ambiente);
    config()->set('pje.callback.hosts_liberados', $hosts);

    $mni = mniOcrApi([9500701 => 'application/pdf']);
    $antes = linhasOcrApi();

    $resposta = pedirOcrApi(corpoOcrApi($this->tribunal, ['callback_url' => $callbackUrl]));

    $resposta->assertStatus(422)
        ->assertJsonValidationErrors(['callback_url' => MENSAGEM_CALLBACK_URL_OCR_API]);
    semSegredosOcrApi($resposta);

    expect($resposta->json('errors.callback_url'))->toBe([MENSAGEM_CALLBACK_URL_OCR_API])
        ->and($mni->requisicoes)->toHaveCount(0)
        ->and(linhasOcrApi())->toBe($antes);
    Queue::assertNothingPushed();
})->with([
    'production com a lista' => ['production', ['host.docker.internal'], CALLBACK_DEV_OCR_API],
    'local com a lista vazia' => ['local', [], CALLBACK_DEV_OCR_API],
    'local com host fora da lista' => ['local', ['host.docker.internal'], 'http://outro.docker.internal:8006/mni/aviso?assinatura=x'],
]);
