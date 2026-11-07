<?php

use App\Jobs\BaixarDocumentoParaOcrJob;
use App\Jobs\EnviarAvisoOcrJob;
use App\Jobs\EnviarDocumentoAoOcrJob;
use App\Jobs\VerificarReenvioOcrJob;
use App\Models\Processo;
use App\Models\Tribunal;
use App\Services\Ocr\SolicitarOcrProcessoService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * Varredura de sigilo do par do PJe no fluxo do OCR (T22; RNF-01, RF-06
 * AC a, RF-09 AC c, RF-11 AC b). Cada fluxo roda com `app.debug` ligado e
 * desligado, com a fila `database` processada pelo worker real
 * (`queue:work --once --stop-when-empty`), como o Horizon faria, e coleta:
 *
 * - os logs (MessageLogged), com a mensagem, o contexto e `(string)` de
 *   toda exceção do contexto, mais a saída do worker;
 * - o corpo e os headers de toda resposta do ms-mni;
 * - as linhas cruas (sem cast nem decifra) das tabelas do OCR, de
 *   `processo_documentos`, `jobs` e `failed_jobs`. O payload em `jobs` é o
 *   mesmo que o Horizon guardaria no Redis;
 * - toda requisição de saída (workflow e avisos).
 *
 * Os traces saem com os argumentos (`zend.exception_ignore_args = 0`) e
 * sem corte de texto (`zend.exception_string_param_max_len` no máximo): um
 * par passado como texto puro apareceria inteiro.
 *
 * Resultado: 0 ocorrências do login e da senha em tudo isso, e 0 do
 * `callback_token` nos logs e nas respostas. O MNI simulado é o único
 * destino do par, e cada fluxo confere que ele chegou lá.
 */

uses(DatabaseTransactions::class);

const LOGIN_SIGILO = 'login-sigilo-7f3a';
const SENHA_SIGILO = 'senha-sigilo-9c1d';
const TOKEN_CALLBACK_SIGILO = 'tok-sigilo-2b8e';
const LOGIN_CARONA_SIGILO = 'login-sigilo-carona-4e6';
const SENHA_CARONA_SIGILO = 'senha-sigilo-carona-1a8';
const TOKEN_CALLBACK_CARONA_SIGILO = 'tok-sigilo-carona-6d3';
const TOKEN_API_SIGILO = 'tk-api-sigilo-5c0';
const TOKEN_API_CARONA_SIGILO = 'tk-api-sigilo-carona-3f9';
const NUMERO_SIGILO = '12345674220268260100';
const BASE_WORKFLOW_SIGILO = 'http://n8n.test/webhook/juridico-ocr';
// O envio antes do download: o documento baixado sai ao workflow antes do
// download seguinte, como os workers paralelos do Horizon fariam.
const FILAS_SIGILO = ['ocr-envio', 'ocr-download', 'ocr-aviso'];
const RECUSA_SIGILO = 'Erro ao realizar login via MNI. exception invoking: loginFailed';
const PRIMEIRO_DOCUMENTO_SIGILO = 9400001;

beforeEach(function () {
    $this->freezeSecond();
    $this->minutoZero = now()->toImmutable();
    Storage::fake('s3');

    $this->iniAntes = [
        'zend.exception_ignore_args' => ini_get('zend.exception_ignore_args'),
        'zend.exception_string_param_max_len' => ini_get('zend.exception_string_param_max_len'),
    ];
    ini_set('zend.exception_ignore_args', '0');
    ini_set('zend.exception_string_param_max_len', '1000000');

    config()->set('queue.default', 'database');
    config()->set('services.n8n_ocr.url', BASE_WORKFLOW_SIGILO);
    config()->set('services.n8n_ocr.token', 'token-workflow-sigilo-7b2');
    config()->set('services.n8n_ocr.base_conhecimento', 'base-processos');
    config()->set('services.n8n_ocr.webhook_base_url', 'http://host.docker.internal:8002');
    config()->set('services.n8n_ocr.prazo_sem_progresso_minutos', 25);
    config()->set('services.n8n_ocr.idade_maxima_sem_retorno_minutos', 20);
    config()->set('services.n8n_ocr.idade_maxima_absoluta_minutos', 60);
    config()->set('services.n8n_ocr.espera_da_trava_segundos', 45);
    config()->set('pje.callback.hosts_liberados', ['sistema.example']);
    definirCredenciaisPadrao('login-padrao-sigilo-0e5', 'senha-padrao-sigilo-2d7');

    criarTokenApi(TOKEN_API_SIGILO);
    criarTokenApi(TOKEN_API_CARONA_SIGILO);

    $this->tribunal = Tribunal::factory()->create([
        'login' => 'login-do-tribunal',
        'password' => Crypt::encrypt('{SENHA}'),
        'url_webservice_mni_consultar_processo' => 'https://tjap-1grau.example/mni/consultar-processo',
        'ativo' => true,
    ]);

    // O banco de teste pode ter dados de desenvolvimento: só as linhas novas contam.
    $this->ultimoJob = (int) DB::table('jobs')->max('id');
    $this->ultimoJobFalho = (int) DB::table('failed_jobs')->max('id');

    $this->coleta = new stdClass;
    $this->coleta->logs = [];
    $this->coleta->respostas = [];
    $this->coleta->linhas = [];

    Event::listen(MessageLogged::class, fn (MessageLogged $log) => $this->coleta->logs[] = textoDoLogSigilo($log));

    $this->mni = mniSigilo();
    $this->workflow = simularHttpSigilo();
});

afterEach(function () {
    foreach ($this->iniAntes as $chave => $valor) {
        ini_set($chave, (string) $valor);
    }
});

// ---------- MNI, workflow e callbacks simulados ----------

/**
 * MNI simulado. `->documentos` (`[id => mimetype]`) é o processo;
 * `->lista` pode trocar a resposta da consulta do processo; os ids em
 * `->falhar` falham sempre no download, sem `loginfailed`; e o par de
 * `->recusarAPartirDe` (`[login => n]`) é recusado a partir do n-ésimo
 * download.
 */
function mniSigilo(): stdClass
{
    $mni = new stdClass;
    $mni->documentos = [];
    $mni->lista = null;
    $mni->falhar = [];
    $mni->recusarAPartirDe = [];
    $mni->downloads = [];

    $mni->registro = simularIntegracaoMni(function (string $operacao, array $parametros) use ($mni) {
        if (! array_key_exists('documento', $parametros)) {
            if ($mni->lista !== null) {
                return ($mni->lista)();
            }

            return (object) [
                'sucesso' => true,
                'processo' => (object) [
                    'dadosBasicos' => (object) [
                        'numero' => NUMERO_SIGILO,
                        'codigoLocalidade' => '1',
                        'classeProcessual' => '7',
                        'nivelSigilo' => 0,
                        'valorCausa' => 1500,
                    ],
                    'movimento' => [],
                    'documento' => collect($mni->documentos)
                        ->map(fn (string $mimetype, int $id) => documentoMniFake($id, ['mimetype' => $mimetype]))
                        ->values()
                        ->all(),
                ],
            ];
        }

        $id = (int) $parametros['documento'];
        $login = $parametros['idConsultante'];
        $mni->downloads[$login] = ($mni->downloads[$login] ?? 0) + 1;

        if (isset($mni->recusarAPartirDe[$login]) && $mni->downloads[$login] >= $mni->recusarAPartirDe[$login]) {
            return (object) ['sucesso' => false, 'mensagem' => RECUSA_SIGILO];
        }

        if (in_array($id, $mni->falhar, true)) {
            throw new SoapFault('Server', 'Could not connect to host');
        }

        return (object) [
            'sucesso' => true,
            'processo' => (object) ['documento' => documentoMniFake($id, ['mimetype' => $mni->documentos[$id]])],
        ];
    });

    return $mni;
}

/**
 * Workflow (ids `job-1`, `job-2`...; `GET jobs/{id}` responde `->jobs[id]`,
 * `running` por padrão; `DELETE` responde 200) e callbacks (200).
 */
function simularHttpSigilo(): stdClass
{
    $workflow = new stdClass;
    $workflow->jobs = [];
    $workflow->novos = 0;

    Http::fake(function (Request $request) use ($workflow) {
        if (! str_starts_with($request->url(), BASE_WORKFLOW_SIGILO)) {
            return Http::response('', 200);
        }

        $caminho = Str::after($request->url(), BASE_WORKFLOW_SIGILO);

        if ($request->method() === 'POST') {
            $workflow->novos++;

            return Http::response(['id' => "job-{$workflow->novos}", 'status' => 'pending', 'error' => null], 202);
        }

        if ($request->method() === 'DELETE') {
            return Http::response(['job_id' => Str::between($caminho, '/jobs/', '/chunks'), 'deleted_chunks' => 2], 200);
        }

        $jobId = Str::after($caminho, '/jobs/');

        return Http::response(['id' => $jobId, 'status' => $workflow->jobs[$jobId] ?? 'running', 'error' => null], 200);
    });

    return $workflow;
}

// ---------- coleta ----------

/**
 * A mensagem e o contexto do log em texto, com `(string)` de cada exceção,
 * que leva a cadeia de `previous` e o trace com os argumentos.
 */
function textoDoLogSigilo(MessageLogged $log): string
{
    return $log->level.' '.$log->message.' '.textoDoValorSigilo($log->context);
}

function textoDoValorSigilo(mixed $valor): string
{
    return match (true) {
        $valor instanceof Throwable => (string) $valor,
        is_array($valor) => implode(' ', array_map(
            fn ($chave, $item) => $chave.'='.textoDoValorSigilo($item),
            array_keys($valor),
            $valor,
        )),
        is_object($valor) => $valor::class.' '.(json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: print_r($valor, true)),
        default => (string) json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    };
}

function registrarRespostaSigilo(TestResponse $resposta): TestResponse
{
    test()->coleta->respostas[] = $resposta->getStatusCode().' '.$resposta->getContent().' '.json_encode($resposta->headers->all());

    return $resposta;
}

/**
 * As linhas cruas do processo da linha, lidas sem cast e sem decifrar.
 * Roda antes de cada job do worker: as linhas de `jobs` somem quando o job
 * termina.
 */
function coletarLinhasSigilo(): void
{
    $processos = Processo::query()->toBase()->where('tribunal_id', test()->tribunal->id)->pluck('id');
    $processamentos = DB::table('processo_ocr_processamentos')->whereIn('processo_id', $processos)->get();
    $assinantes = DB::table('processo_ocr_assinantes')->whereIn('processamento_id', $processamentos->pluck('id'))->get();

    test()->coleta->linhas[] = json_encode([
        'processo_ocr_processamentos' => $processamentos,
        'processo_ocr_assinantes' => $assinantes,
        'processo_ocr_avisos' => DB::table('processo_ocr_avisos')->whereIn('assinante_id', $assinantes->pluck('id'))->get(),
        'processo_documentos' => DB::table('processo_documentos')->whereIn('processo_id', $processos)->get(),
        'jobs' => DB::table('jobs')->where('id', '>', test()->ultimoJob)->get(),
        'failed_jobs' => DB::table('failed_jobs')->where('id', '>', test()->ultimoJobFalho)->get(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

/**
 * Roda o worker real, um job por vez, até a fila do OCR esvaziar. Um job
 * devolvido com backoff espera o relógio chegar ao `available_at`.
 */
function esvaziarFilaSigilo(): void
{
    for ($passo = 0; $passo < 100; $passo++) {
        coletarLinhasSigilo();

        $proximo = DB::table('jobs')
            ->where('id', '>', test()->ultimoJob)
            ->whereIn('queue', FILAS_SIGILO)
            ->orderBy('available_at')
            ->orderBy('id')
            ->first();

        if ($proximo === null) {
            return;
        }

        if ($proximo->available_at > now()->getTimestamp()) {
            test()->travelTo(Carbon::createFromTimestamp($proximo->available_at));
        }

        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => implode(',', FILAS_SIGILO),
            '--once' => true,
            '--stop-when-empty' => true,
            '--sleep' => 0,
        ]);

        test()->coleta->logs[] = Artisan::output();
    }

    throw new RuntimeException('A fila do OCR não esvaziou em 100 passos do worker');
}

function acompanharSigilo(int $minuto): void
{
    test()->travelTo(test()->minutoZero->addMinutes($minuto));

    expect(Artisan::call('ocr:acompanhar'))->toBe(0);
    test()->coleta->logs[] = Artisan::output();

    esvaziarFilaSigilo();
}

// ---------- pedidos e retornos ----------

/**
 * @return array<string, mixed>
 */
function corpoSigilo(bool $carona = false): array
{
    return [
        'numero_processo' => NUMERO_SIGILO,
        'tribunal_id' => test()->tribunal->id,
        'login_pje' => $carona ? LOGIN_CARONA_SIGILO : LOGIN_SIGILO,
        'senha_pje' => $carona ? SENHA_CARONA_SIGILO : SENHA_SIGILO,
        'callback_url' => 'https://sistema.example/mni/processamentos/'.($carona ? 'carona' : 'dono').'/progresso?signature=x1',
        'callback_token' => $carona ? TOKEN_CALLBACK_CARONA_SIGILO : TOKEN_CALLBACK_SIGILO,
    ];
}

function pedirSigilo(array $corpo, ?string $tokenApi = TOKEN_API_SIGILO): TestResponse
{
    return registrarRespostaSigilo(test()->postJson(
        '/api/processo/ocr',
        $corpo,
        $tokenApi === null ? [] : ['X-API-Token' => $tokenApi],
    ));
}

/**
 * @return list<int>
 */
function documentosSigilo(int $quantidade): array
{
    $ids = range(PRIMEIRO_DOCUMENTO_SIGILO, PRIMEIRO_DOCUMENTO_SIGILO + $quantidade - 1);
    test()->mni->documentos = array_fill_keys($ids, 'application/pdf');

    return $ids;
}

/**
 * O workflow chama o `webhook_url` do envio do documento (o último, ou o
 * `$ordem`-ésimo a partir de 0).
 */
function retornoSigilo(int $idDocumento, string $status = 'success', int $ordem = -1): TestResponse
{
    $envios = Http::recorded(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === BASE_WORKFLOW_SIGILO.'/documents/process'
            && (int) $request->data()['metadata']['numero_documento'] === $idDocumento)
        ->values();

    expect($envios)->not->toBeEmpty();

    [$envio, $resposta] = $envios[$ordem < 0 ? $envios->count() + $ordem : $ordem];

    return registrarRespostaSigilo(test()->postJson($envio->data()['webhook_url'], [
        'status' => $status,
        'message' => $status === 'success' ? 'OCR + RAG processados com sucesso' : 'Falha ao processar OCR+RAG',
        'job_id' => $resposta->json('id'),
    ], ['Accept' => '*/*']));
}

/**
 * @return list<string> "MÉTODO caminho" das chamadas ao workflow
 */
function chamadasAoWorkflowSigilo(): array
{
    return Http::recorded(fn (Request $request) => str_starts_with($request->url(), BASE_WORKFLOW_SIGILO))
        ->map(fn (array $par) => $par[0]->method().' '.Str::after($par[0]->url(), BASE_WORKFLOW_SIGILO))
        ->values()
        ->all();
}

/**
 * @return list<array<string, mixed>> os corpos dos avisos enviados aos callbacks
 */
function avisosSigilo(): array
{
    return Http::recorded(fn (Request $request) => str_starts_with($request->url(), 'https://sistema.example/'))
        ->map(fn (array $par) => $par[0]->data())
        ->values()
        ->all();
}

// ---------- a varredura ----------

/**
 * 0 ocorrências do par (do dono e do carona) nos logs, nas respostas, nas
 * linhas cruas e nas requisições de saída, e 0 do `callback_token` nos
 * logs e nas respostas.
 */
function expectSemSegredosSigilo(bool $comProcessamento = true): void
{
    coletarLinhasSigilo();

    $saidas = Http::recorded()
        ->map(fn (array $par) => $par[0]->method().' '.$par[0]->url().' '.json_encode($par[0]->headers()).' '.$par[0]->body())
        ->implode("\n");

    $onde = [
        'logs' => implode("\n", test()->coleta->logs),
        'respostas' => implode("\n", test()->coleta->respostas),
        'linhas' => implode("\n", test()->coleta->linhas),
        'saídas' => $saidas,
    ];

    // A coleta não está vazia: há o que varrer em cada canal.
    expect($onde['logs'])->not->toBe('')
        ->and($onde['respostas'])->not->toBe('')
        ->and($onde['linhas'])->toContain('processo_documentos');

    if ($comProcessamento) {
        expect($onde['logs'])->toContain('[ProcessamentoOcr:')
            ->and($onde['saídas'])->toContain(BASE_WORKFLOW_SIGILO);
    }

    foreach ($onde as $canal => $texto) {
        foreach ([LOGIN_SIGILO, SENHA_SIGILO, LOGIN_CARONA_SIGILO, SENHA_CARONA_SIGILO] as $segredo) {
            expect(substr_count($texto, $segredo))->toBe(0, "{$segredo} apareceu em {$canal}");
        }
    }

    foreach (['logs', 'respostas'] as $canal) {
        foreach ([TOKEN_CALLBACK_SIGILO, TOKEN_CALLBACK_CARONA_SIGILO] as $segredo) {
            expect(substr_count($onde[$canal], $segredo))->toBe(0, "{$segredo} apareceu em {$canal}");
        }
    }
}

/**
 * O par chegou ao MNI simulado, o único destino dele: a varredura não
 * passa por falta do segredo no fluxo.
 */
function expectParNoMniSigilo(string $login, string $senha): void
{
    $comOPar = array_filter(
        test()->mni->registro->requisicoes,
        fn (array $requisicao) => ($requisicao['parametros']['idConsultante'] ?? null) === $login
            && ($requisicao['parametros']['senhaConsultante'] ?? null) === $senha,
    );

    expect($comOPar)->not->toBeEmpty();
}

function linhasColetadasSigilo(): string
{
    return implode("\n", test()->coleta->linhas);
}

$debug = ['app.debug ligado' => true, 'app.debug desligado' => false];

// ---------- fluxos ----------

it('pedido novo, carona, download, envio, retorno, avisos e o pedido concluido: o par e o callback_token não vazam', function (bool $debug) {
    config()->set('app.debug', $debug);
    $documentos = documentosSigilo(2);

    pedirSigilo(corpoSigilo())->assertStatus(202)->assertJsonPath('situacao', 'em_andamento');
    // O carona entra com os downloads ainda na fila.
    pedirSigilo(corpoSigilo(carona: true), TOKEN_API_CARONA_SIGILO)->assertStatus(202)->assertJsonPath('situacao', 'em_andamento');

    esvaziarFilaSigilo();

    foreach ($documentos as $id) {
        retornoSigilo($id)->assertOk();
    }

    esvaziarFilaSigilo();

    pedirSigilo(corpoSigilo())->assertStatus(202)->assertJsonPath('situacao', 'concluido');

    // O download cifrado e o envio, o aviso sem cifra passaram pela fila e foram varridos.
    expect(linhasColetadasSigilo())->toContain('BaixarDocumentoParaOcrJob')
        ->toContain('EnviarDocumentoAoOcrJob')
        ->toContain('EnviarAvisoOcrJob')
        ->and(array_column(avisosSigilo(), 'situacao'))->toBe([
            'em_andamento', 'em_andamento', 'em_andamento', 'em_andamento', 'concluido', 'concluido',
        ])
        ->and(DB::table('failed_jobs')->where('id', '>', $this->ultimoJobFalho)->count())->toBe(0);
    expectParNoMniSigilo(LOGIN_SIGILO, SENHA_SIGILO);
    expectSemSegredosSigilo();
})->with($debug);

it('download com falha definitiva e o job em failed_jobs: o par não vaza no trace da exceção nem no payload', function (bool $debug) {
    config()->set('app.debug', $debug);
    [$falha, $ok] = documentosSigilo(2);
    $this->mni->falhar = [$falha];

    pedirSigilo(corpoSigilo())->assertStatus(202);
    esvaziarFilaSigilo();
    retornoSigilo($ok)->assertOk();
    esvaziarFilaSigilo();

    $falhos = DB::table('failed_jobs')->where('id', '>', $this->ultimoJobFalho)->get();

    // 3 tentativas com o backoff e, ao fim, o job em failed_jobs com o
    // trace como texto: os argumentos aparecem, e o par sai mascarado.
    expect($falhos)->toHaveCount(1)
        ->and($falhos[0]->payload)->toContain('BaixarDocumentoParaOcrJob')
        ->and($falhos[0]->exception)->toContain('MNIException')
        ->and($falhos[0]->exception)->toContain('SensitiveParameterValue')
        ->and($this->mni->downloads[LOGIN_SIGILO])->toBe(4)
        ->and(implode("\n", $this->coleta->logs))->toContain('Stack trace')
        ->and(last(avisosSigilo()))->toMatchArray(['situacao' => 'concluido', 'processados' => 1, 'com_erro' => 1]);
    expectParNoMniSigilo(LOGIN_SIGILO, SENHA_SIGILO);
    expectSemSegredosSigilo();
})->with($debug);

it('download com recusa do par no meio: o par não vaza, e o final falhou sai por papel', function (bool $debug) {
    config()->set('app.debug', $debug);
    [$enviado] = documentosSigilo(3);
    $this->mni->recusarAPartirDe = [LOGIN_SIGILO => 2];

    pedirSigilo(corpoSigilo())->assertStatus(202);
    pedirSigilo(corpoSigilo(carona: true), TOKEN_API_CARONA_SIGILO)->assertStatus(202);
    esvaziarFilaSigilo();

    // O retorno do documento enviado antes da recusa chega sem processamento aberto.
    retornoSigilo($enviado)->assertOk();
    esvaziarFilaSigilo();

    expect(array_map(fn (array $aviso) => $aviso['codigo'] ?? null, avisosSigilo()))->toBe([
        'credencial_pje_recusada', 'credencial_de_outro_pedido_recusada',
    ])->and($this->mni->downloads[LOGIN_SIGILO])->toBe(2);
    expectParNoMniSigilo(LOGIN_SIGILO, SENHA_SIGILO);
    expectSemSegredosSigilo();
})->with($debug);

it('envio, consulta do job antes do reenvio, retorno, aviso e acompanhamento até o fim por P: o par não vaza', function (bool $debug) {
    config()->set('app.debug', $debug);
    [$id] = documentosSigilo(1);

    pedirSigilo(corpoSigilo())->assertStatus(202);
    esvaziarFilaSigilo();

    // Rodada dos 20 min: o job antigo em failed leva ao cancelamento e ao reenvio.
    $this->workflow->jobs['job-1'] = 'failed';
    acompanharSigilo(20);
    retornoSigilo($id, ordem: 0)->assertNotFound();

    // Rodada dos 25 min: P vence com o job novo em running.
    acompanharSigilo(25);
    retornoSigilo($id)->assertOk();
    esvaziarFilaSigilo();

    expect(chamadasAoWorkflowSigilo())->toBe([
        'POST /documents/process', 'GET /jobs/job-1', 'DELETE /jobs/job-1/chunks', 'POST /documents/process', 'GET /jobs/job-2',
    ])->and(linhasColetadasSigilo())->toContain('VerificarReenvioOcrJob')
        ->and(array_column(avisosSigilo(), 'situacao'))->toBe(['falhou']);
    expectParNoMniSigilo(LOGIN_SIGILO, SENHA_SIGILO);
    expectSemSegredosSigilo();
})->with($debug);

it('pedidos com 401, 403, 404, 422 e 500: o corpo e os headers não devolvem o par nem o callback_token, e nada é gravado com eles', function (bool $debug) {
    config()->set('app.debug', $debug);
    documentosSigilo(1);

    pedirSigilo(corpoSigilo(), tokenApi: null)->assertUnauthorized();
    pedirSigilo(corpoSigilo(), tokenApi: 'tk-invalido-sigilo')->assertUnauthorized();
    pedirSigilo(array_merge(corpoSigilo(), ['callback_url' => null]))->assertUnprocessable();
    pedirSigilo(array_merge(corpoSigilo(), ['tribunal_id' => 0]))->assertUnprocessable();

    $this->mni->lista = fn () => (object) ['sucesso' => false, 'mensagem' => RECUSA_SIGILO];
    pedirSigilo(corpoSigilo())->assertForbidden()->assertJsonPath('codigo', 'credencial_pje_recusada');

    $this->mni->lista = fn () => (object) ['sucesso' => false, 'mensagem' => 'Processo de número '.NUMERO_SIGILO.' não encontrado!'];
    pedirSigilo(corpoSigilo())->assertNotFound()->assertJsonPath('codigo', 'processo_nao_encontrado');

    $this->mni->lista = fn () => throw new SoapFault('Server', 'Could not connect to host');
    pedirSigilo(corpoSigilo())->assertStatus(500);

    // 500 pela espera da trava esgotada (W = 0), com a decisão presa.
    config()->set('services.n8n_ocr.espera_da_trava_segundos', 0);
    $trava = Cache::lock(SolicitarOcrProcessoService::chaveDaTrava($this->tribunal->id, NUMERO_SIGILO), 60);
    expect($trava->get())->toBeTrue();
    pedirSigilo(corpoSigilo())->assertStatus(500);
    $trava->release();

    // Nada foi aberto nem enfileirado.
    esvaziarFilaSigilo();
    expect(DB::table('jobs')->where('id', '>', $this->ultimoJob)->count())->toBe(0)
        ->and(DB::table('processo_ocr_assinantes')->where('callback_url', 'like', 'https://sistema.example/%')->count())->toBe(0);

    // As 3 consultas ao MNI (403, 404 e 500) levaram o par.
    expect($this->mni->registro->requisicoes)->toHaveCount(3);
    expectParNoMniSigilo(LOGIN_SIGILO, SENHA_SIGILO);
    expect(implode("\n", $this->coleta->logs))->toContain('Pedido de OCR falhou');
    expectSemSegredosSigilo(comProcessamento: false);
})->with($debug);

// ---------- reflection ----------

it('por reflection, todo job que leva o par é ShouldBeEncrypted, com o par sensível no construtor do OCR, e o envio, a consulta e o aviso do OCR não têm o par', function () {
    $temOPar = function (string $classe): bool {
        $reflexao = new ReflectionClass($classe);
        $nomes = [
            ...array_map(fn (ReflectionProperty $propriedade) => $propriedade->getName(), $reflexao->getProperties()),
            ...array_map(fn (ReflectionParameter $parametro) => $parametro->getName(), $reflexao->getConstructor()?->getParameters() ?? []),
        ];

        return collect($nomes)->contains(fn (string $nome) => preg_match('/login|senha/i', $nome) === 1);
    };

    $jobs = collect(glob(app_path('Jobs/*.php')))
        ->map(fn (string $arquivo) => 'App\\Jobs\\'.basename($arquivo, '.php'))
        ->filter(fn (string $classe) => is_subclass_of($classe, ShouldQueue::class))
        ->values();

    $comOPar = $jobs->filter($temOPar)->values();

    // Os 6 jobs que já levavam o par (NC-5) e o download do OCR.
    expect($comOPar->sort()->values()->all())->toBe([
        'App\\Jobs\\BaixarDocumentoMNIJob',
        'App\\Jobs\\BaixarDocumentoParaOcrJob',
        'App\\Jobs\\BaixarDocumentosMNIEmLoteJob',
        'App\\Jobs\\BaixarProcessoMNIJob',
        'App\\Jobs\\ConsultarDadosBasicosProcessoMNIJob',
        'App\\Jobs\\ConsultarDocumentosProcessoMNIJob',
        'App\\Jobs\\ConsultarMovimentosProcessoMNIJob',
    ]);

    foreach ($comOPar as $classe) {
        expect(is_subclass_of($classe, ShouldBeEncrypted::class))->toBeTrue("{$classe} leva o par sem ShouldBeEncrypted");
    }

    foreach (['login', 'senha'] as $nome) {
        $parametro = collect((new ReflectionMethod(BaixarDocumentoParaOcrJob::class, '__construct'))->getParameters())
            ->firstWhere(fn (ReflectionParameter $p) => $p->getName() === $nome);

        expect($parametro)->not->toBeNull()
            ->and($parametro->getAttributes(SensitiveParameter::class))->toHaveCount(1);
    }

    foreach ([EnviarDocumentoAoOcrJob::class, VerificarReenvioOcrJob::class, EnviarAvisoOcrJob::class] as $classe) {
        expect($temOPar($classe))->toBeFalse("{$classe} não pode levar o par");
    }
});
