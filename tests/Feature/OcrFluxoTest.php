<?php

use App\Jobs\BaixarDocumentoParaOcrJob;
use App\Jobs\EnviarAvisoOcrJob;
use App\Jobs\EnviarDocumentoAoOcrJob;
use App\Jobs\VerificarReenvioOcrJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\ProcessoOcrAviso;
use App\Models\ProcessoOcrProcessamento;
use App\Models\Tribunal;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Yaml\Yaml;

/*
 * Fluxos ponta a ponta do OCR do processo (T21; AC1, AC3, AC4, AC5, AC9,
 * AC10): o pedido pela rota, o download pelo MNI simulado
 * (simularIntegracaoMni), o envio ao workflow simulado (Http::fake), o
 * retorno pelo `webhook_url` capturado do envio (postJson, como o n8n
 * chamaria), o acompanhamento (`ocr:acompanhar`) e os avisos aos callbacks.
 *
 * Fila `sync` (FilaSyncFluxoOcr): cada job roda na hora, como a do
 * phpunit.xml. Todo 202 e todo aviso conferem o invariante de RF-08, e todo
 * aviso valida contra o schema AvisoDeProgressoDoOcr de docs/api/openapi.yaml.
 */

uses(DatabaseTransactions::class);

const NUMERO_FLUXO_OCR = '12345674220268260100';
const BASE_WORKFLOW_FLUXO_OCR = 'http://n8n.test/webhook/juridico-ocr';
const TOKEN_WORKFLOW_FLUXO_OCR = 'token-workflow-fluxo-4d7';
const WEBHOOK_BASE_FLUXO_OCR = 'http://host.docker.internal:8002';
const URL_LINHA_FLUXO_OCR = 'https://tjap-1grau.example/mni/consultar-processo';
const LOGIN_PADRAO_FLUXO_OCR = 'login-padrao-fluxo-8c1';
const SENHA_PADRAO_FLUXO_OCR = 'senha-padrao-fluxo-2e9';
const RECUSA_FLUXO_OCR = 'Erro ao realizar login via MNI. exception invoking: loginFailed';
const POST_FLUXO_OCR = 'POST /documents/process';
const PRIMEIRO_DOCUMENTO_FLUXO_OCR = 9300001;

/**
 * A fila `sync` deste arquivo. Roda cada job na hora, como a `sync` do
 * phpunit.xml, com duas diferenças que deixam o fluxo igual ao de produção:
 *
 * - a falha de um job não sobe até quem despachou. Na `sync` pura, a
 *   exceção de um download sairia pelo pedido HTTP (500) e cortaria o
 *   despacho dos outros documentos; o worker do Horizon registra a falha e
 *   segue. Aqui, o job falho chama o failed() na hora (as tentativas acabam
 *   na 1ª falha) e fica em `falhas`, que cada teste confere;
 * - os jobs das classes em `reter` ficam guardados, como na fila enquanto
 *   os workers estão ocupados, até `soltar()`. Assim um carona entra antes
 *   dos downloads, e um envio sai minutos depois da abertura.
 *
 * Todo job que passa pela fila fica em `empurrados`, para contar os jobs de
 * cada pedido.
 */
final class FilaSyncFluxoOcr extends SyncQueue
{
    /** @var list<class-string> */
    public array $reter = [];

    /** @var list<array{0: object, 1: mixed, 2: ?string}> */
    public array $retidos = [];

    /** @var list<object> */
    public array $empurrados = [];

    /** @var list<array{job: string, erro: Throwable}> */
    public array $falhas = [];

    public function push($job, $data = '', $queue = null)
    {
        if (is_object($job)) {
            $this->empurrados[] = $job;

            if (in_array($job::class, $this->reter, true)) {
                $this->retidos[] = [$job, $data, $queue];

                return null;
            }
        }

        return parent::push($job, $data, $queue);
    }

    /**
     * Roda os retidos que passam no filtro, na ordem em que entraram.
     */
    public function soltar(?Closure $filtro = null): void
    {
        [$soltos, $ficam] = collect($this->retidos)
            ->partition(fn (array $retido) => $filtro === null || $filtro($retido[0]));

        $this->retidos = $ficam->values()->all();

        foreach ($soltos as [$job, $data, $queue]) {
            parent::push($job, $data, $queue);
        }
    }

    protected function handleException(Job $queueJob, Throwable $e)
    {
        $this->raiseExceptionOccurredJobEvent($queueJob, $e);
        $this->falhas[] = ['job' => $queueJob->resolveName(), 'erro' => $e];

        $queueJob->fail($e);
    }
}

beforeEach(function () {
    $this->freezeSecond();
    $this->minutoZero = now()->toImmutable();
    Storage::fake('s3');

    $fila = $this->fila = new FilaSyncFluxoOcr;
    Queue::extend('sync', fn () => new class($fila) implements ConnectorInterface
    {
        public function __construct(private readonly FilaSyncFluxoOcr $fila) {}

        public function connect(array $config)
        {
            return $this->fila;
        }
    });
    config()->set('queue.default', 'sync');
    expect(Queue::connection())->toBe($this->fila);

    config()->set('services.n8n_ocr.url', BASE_WORKFLOW_FLUXO_OCR);
    config()->set('services.n8n_ocr.token', TOKEN_WORKFLOW_FLUXO_OCR);
    config()->set('services.n8n_ocr.base_conhecimento', 'base-processos');
    config()->set('services.n8n_ocr.webhook_base_url', WEBHOOK_BASE_FLUXO_OCR);
    config()->set('services.n8n_ocr.prazo_sem_progresso_minutos', 25);
    config()->set('services.n8n_ocr.idade_maxima_sem_retorno_minutos', 20);
    config()->set('services.n8n_ocr.idade_maxima_absoluta_minutos', 60);
    config()->set('services.n8n_ocr.espera_da_trava_segundos', 45);

    // Callbacks sem DNS: fora de `production`, o host liberado é aceito (RF-17).
    config()->set('pje.callback.hosts_liberados', ['sistema.example']);

    // O par padrão existe no ambiente, mas o OCR nunca o usa (RF-02).
    definirCredenciaisPadrao(LOGIN_PADRAO_FLUXO_OCR, SENHA_PADRAO_FLUXO_OCR);

    $this->tribunal = Tribunal::factory()->create([
        'sigla' => 'TJAP',
        'grau' => '1',
        'login' => 'login-do-tribunal',
        'password' => Crypt::encrypt('{SENHA}'),
        'url_webservice_mni_consultar_processo' => URL_LINHA_FLUXO_OCR,
        'ativo' => true,
    ]);

    // ArrayObject: o test() de Pest não deixa alterar um array por dentro.
    $this->tokensPorCallback = new ArrayObject;
    $this->processoMni = mniFluxoOcr();
    [$this->workflow, $this->callbacks] = simularHttpFluxoOcr();
});

// ---------- MNI, workflow e callbacks simulados ----------

/**
 * MNI simulado. A consulta do processo devolve `->documentos`
 * (`[id_documento => mimetype]`); a consulta de um documento devolve o
 * binário, salvo os ids em `->falhar` (falha sem `loginfailed`) e os
 * downloads com um login de `->recusarAPartirDe` (`[login => n]`): o
 * tribunal recusa esse par a partir do n-ésimo download feito com ele.
 */
function mniFluxoOcr(): stdClass
{
    $mni = new stdClass;
    $mni->documentos = [];
    $mni->falhar = [];
    $mni->recusarAPartirDe = [];
    $mni->downloadsPorLogin = [];

    $mni->registro = simularIntegracaoMni(function (string $operacao, array $parametros) use ($mni) {
        if (! array_key_exists('documento', $parametros)) {
            return (object) [
                'sucesso' => true,
                'processo' => (object) [
                    'dadosBasicos' => (object) [
                        'numero' => NUMERO_FLUXO_OCR,
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
        $mni->downloadsPorLogin[$login] = ($mni->downloadsPorLogin[$login] ?? 0) + 1;

        if (isset($mni->recusarAPartirDe[$login]) && $mni->downloadsPorLogin[$login] >= $mni->recusarAPartirDe[$login]) {
            return (object) ['sucesso' => false, 'mensagem' => RECUSA_FLUXO_OCR];
        }

        if (in_array($id, $mni->falhar, true)) {
            return (object) ['sucesso' => false, 'mensagem' => 'Documento indisponível no momento'];
        }

        return (object) [
            'sucesso' => true,
            'processo' => (object) ['documento' => documentoMniFake($id, ['mimetype' => $mni->documentos[$id]])],
        ];
    });

    return $mni;
}

/**
 * Workflow e callbacks simulados num só Http::fake.
 *
 * Workflow: `POST documents/process` aceita com ids sequenciais (`job-1`,
 * `job-2`...); `GET jobs/{id}` responde `->jobs[id]` (`running` por padrão;
 * `'404'` para job inexistente); `DELETE jobs/{id}/chunks` responde 200.
 *
 * Callbacks: cada aviso fica em `->requisicoes`, com o payload, o token, a
 * chave de idempotência, o status respondido e os documentos do processo no
 * banco naquele momento (RF-08). `->responder` decide o status (200 por
 * padrão). Nada é conferido aqui dentro: uma falha de expectativa num job
 * viraria falha do job, e não do teste.
 *
 * @return array{0: stdClass, 1: stdClass}
 */
function simularHttpFluxoOcr(): array
{
    $workflow = new stdClass;
    $workflow->jobs = [];
    $workflow->novos = 0;

    $callbacks = new stdClass;
    $callbacks->requisicoes = [];
    $callbacks->responder = fn (array $aviso, array $anteriores) => 200;

    Http::fake(function (Request $request) use ($workflow, $callbacks) {
        if (str_starts_with($request->url(), BASE_WORKFLOW_FLUXO_OCR)) {
            return respostaDoWorkflowFluxoOcr($workflow, $request);
        }

        $payload = $request->data();
        $aviso = [
            'url' => $request->url(),
            'token' => $request->header('X-API-Token')[0] ?? null,
            'chave' => $request->header('X-Idempotency-Key')[0] ?? null,
            'payload' => $payload,
            'documentos_no_banco' => documentosNoBancoFluxoOcr($payload['numero_processo'] ?? '', (int) ($payload['tribunal_id'] ?? 0)),
        ];
        $aviso['status'] = ($callbacks->responder)($aviso, $callbacks->requisicoes);
        $callbacks->requisicoes[] = $aviso;

        return Http::response('', $aviso['status']);
    });

    return [$workflow, $callbacks];
}

function respostaDoWorkflowFluxoOcr(stdClass $workflow, Request $request)
{
    $caminho = Str::after($request->url(), BASE_WORKFLOW_FLUXO_OCR);

    if ($request->method() === 'POST' && $caminho === '/documents/process') {
        $workflow->novos++;

        return Http::response(['id' => "job-{$workflow->novos}", 'status' => 'pending', 'error' => null], 202);
    }

    if ($request->method() === 'DELETE' && str_ends_with($caminho, '/chunks')) {
        return Http::response(['job_id' => rawurldecode(Str::between($caminho, '/jobs/', '/chunks')), 'deleted_chunks' => 4], 200);
    }

    if ($request->method() === 'GET' && str_starts_with($caminho, '/jobs/')) {
        $jobId = rawurldecode(Str::after($caminho, '/jobs/'));
        $status = $workflow->jobs[$jobId] ?? 'running';

        if ($status === '404') {
            return Http::response(['detail' => 'Job não encontrado'], 404);
        }

        return Http::response([
            'id' => $jobId,
            'status' => $status,
            'filename' => null,
            'progress' => $status === 'success' ? 100 : 40,
            'progress_message' => null,
            'error' => $status === 'failed' ? 'Falha no Gemini' : null,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ], 200);
    }

    return Http::response(['detail' => 'rota do workflow não simulada'], 500);
}

function documentosNoBancoFluxoOcr(string $numeroProcesso, int $tribunalId): int
{
    $processo = Processo::query()->toBase()
        ->where('tribunal_id', $tribunalId)
        ->where('numero_processo', $numeroProcesso)
        ->value('id');

    return $processo === null ? 0 : ProcessoDocumento::query()->where('processo_id', $processo)->count();
}

// ---------- pedidos, retornos e rodadas ----------

/**
 * Um pedido do Sistema: token, par e callback próprios.
 *
 * @return array{nome: string, token: string, login_pje: string, senha_pje: string, callback_url: string, callback_token: string}
 */
function solicitanteFluxoOcr(string $nome): array
{
    $token = "tk-fluxo-ocr-{$nome}";
    criarTokenApi($token);

    $solicitante = [
        'nome' => $nome,
        'token' => $token,
        'login_pje' => "login-{$nome}-fluxo-ocr",
        'senha_pje' => "senha-{$nome}-fluxo-ocr",
        // Com query e um caractere codificado: a URL chamada tem de ser byte a byte esta (RF-14 AC a).
        'callback_url' => "https://sistema.example/mni/processamentos/{$nome}/progresso?signature=a1b2%2B{$nome}&expires=1762473600",
        'callback_token' => "tok-callback-{$nome}",
    ];

    test()->tokensPorCallback[$solicitante['callback_url']] = $solicitante['callback_token'];

    return $solicitante;
}

function pedirOcrFluxo(array $solicitante): TestResponse
{
    return test()->postJson('/api/processo/ocr', [
        'numero_processo' => NUMERO_FLUXO_OCR,
        'tribunal_id' => test()->tribunal->id,
        'login_pje' => $solicitante['login_pje'],
        'senha_pje' => $solicitante['senha_pje'],
        'callback_url' => $solicitante['callback_url'],
        'callback_token' => $solicitante['callback_token'],
    ], ['X-API-Token' => $solicitante['token']]);
}

/**
 * `$quantidade` PDFs no MNI, com ids a partir de PRIMEIRO_DOCUMENTO_FLUXO_OCR,
 * mais os `$extras` (`[id => mimetype]`).
 *
 * @return list<int> os ids dos PDFs, na ordem
 */
function documentosNoMniFluxoOcr(int $quantidade, array $extras = []): array
{
    $ids = range(PRIMEIRO_DOCUMENTO_FLUXO_OCR, PRIMEIRO_DOCUMENTO_FLUXO_OCR + $quantidade - 1);

    test()->processoMni->documentos = array_fill_keys($ids, 'application/pdf') + $extras;

    return $ids;
}

/**
 * Os envios ao workflow, na ordem: o documento, o job devolvido pelo 202 e
 * o `webhook_url` mandado no corpo.
 *
 * @return list<array{documento: int, job_id: string, webhook_url: string}>
 */
function enviosFluxoOcr(): array
{
    return Http::recorded(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === BASE_WORKFLOW_FLUXO_OCR.'/documents/process')
        ->map(fn (array $par) => [
            'documento' => (int) $par[0]->data()['metadata']['numero_documento'],
            'job_id' => $par[1]->json('id'),
            'webhook_url' => $par[0]->data()['webhook_url'],
        ])
        ->values()
        ->all();
}

/**
 * O último envio do documento (ou o `$ordem`-ésimo, a partir de 0).
 *
 * @return array{documento: int, job_id: string, webhook_url: string}
 */
function envioDoDocumentoFluxoOcr(int $idDocumento, int $ordem = -1): array
{
    $envios = array_values(array_filter(enviosFluxoOcr(), fn (array $envio) => $envio['documento'] === $idDocumento));

    expect($envios)->not->toBeEmpty();

    return $ordem < 0 ? $envios[count($envios) + $ordem] : $envios[$ordem];
}

/**
 * O workflow chama o `webhook_url` do envio, como os nós "Avisar o sistema"
 * e "Avisar falha": corpo JSON, sem `Accept: application/json` e sem
 * X-API-Token.
 */
function retornoDoWorkflowFluxoOcr(array $envio, string $status = 'success'): TestResponse
{
    $corpo = $status === 'success'
        ? ['status' => 'success', 'message' => 'OCR + RAG processados com sucesso', 'job_id' => $envio['job_id'],
            'bucket_destino' => null, 'path_destino' => null, 'indexed_chunks' => 12]
        : ['status' => 'error', 'message' => 'Falha ao processar OCR+RAG', 'job_id' => $envio['job_id'],
            'error_detail' => 'Gemini indisponível', 'error' => 'Gemini indisponível'];

    return test()->postJson($envio['webhook_url'], $corpo, ['Accept' => '*/*']);
}

/** O retorno `success` do último envio de cada documento, na ordem dada. */
function retornarTodosFluxoOcr(array $idsDocumentos): void
{
    foreach ($idsDocumentos as $id) {
        retornoDoWorkflowFluxoOcr(envioDoDocumentoFluxoOcr($id))->assertOk()->assertExactJson(['message' => 'OK']);
    }
}

/**
 * Rodadas do acompanhamento, uma por minuto, como o agendamento faria.
 * `$antes` roda no começo de cada minuto, antes da rodada.
 */
function rodadasFluxoOcr(int $deMinuto, int $ateMinuto, ?Closure $antes = null): void
{
    foreach (range($deMinuto, $ateMinuto) as $minuto) {
        test()->travelTo(test()->minutoZero->addMinutes($minuto));

        if ($antes !== null) {
            $antes($minuto);
        }

        expect(Artisan::call('ocr:acompanhar'))->toBe(0);
    }
}

/**
 * As chamadas ao workflow, na ordem: "MÉTODO caminho".
 *
 * @return list<string>
 */
function chamadasAoWorkflowFluxoOcr(): array
{
    return Http::recorded(fn (Request $request) => str_starts_with($request->url(), BASE_WORKFLOW_FLUXO_OCR))
        ->map(fn (array $par) => $par[0]->method().' '.rawurldecode(Str::after($par[0]->url(), BASE_WORKFLOW_FLUXO_OCR)))
        ->values()
        ->all();
}

/**
 * O que `$acao` fez: as chamadas ao workflow, o SOAP, os jobs que entraram
 * na fila e as requisições aos callbacks.
 *
 * @return array{workflow: list<string>, soap: list<array>, jobs: list<object>, avisos: list<array>}
 */
function duranteFluxoOcr(Closure $acao): array
{
    $workflow = count(chamadasAoWorkflowFluxoOcr());
    $soap = count(test()->processoMni->registro->requisicoes);
    $jobs = count(test()->fila->empurrados);
    $avisos = count(test()->callbacks->requisicoes);

    $acao();

    return [
        'workflow' => array_slice(chamadasAoWorkflowFluxoOcr(), $workflow),
        'soap' => array_slice(test()->processoMni->registro->requisicoes, $soap),
        'jobs' => array_slice(test()->fila->empurrados, $jobs),
        'avisos' => array_slice(test()->callbacks->requisicoes, $avisos),
    ];
}

/**
 * @param  list<object>  $jobs
 * @return list<int> os documentos dos jobs da classe, na ordem
 */
function documentosDosJobsFluxoOcr(array $jobs, string $classe): array
{
    return array_values(array_map(
        fn (object $job) => ProcessoDocumento::findOrFail($job->documentoId)->id_documento,
        array_filter($jobs, fn (object $job) => $job instanceof $classe),
    ));
}

function processoFluxoOcr(): Processo
{
    return Processo::query()
        ->where('tribunal_id', test()->tribunal->id)
        ->where('numero_processo', NUMERO_FLUXO_OCR)
        ->sole();
}

function documentoFluxoOcr(int $idDocumento): ProcessoDocumento
{
    return ProcessoDocumento::query()
        ->where('processo_id', processoFluxoOcr()->id)
        ->where('id_documento', $idDocumento)
        ->sole();
}

/**
 * @return list<ProcessoOcrProcessamento>
 */
function processamentosFluxoOcr(): array
{
    return ProcessoOcrProcessamento::query()->where('processo_id', processoFluxoOcr()->id)->orderBy('id')->get()->all();
}

// ---------- conferências ----------

/**
 * O 202 com exatamente as chaves de ProcessamentoDeOcr, válido contra o
 * schema, com os valores esperados e o invariante de RF-08.
 */
function expect202FluxoOcr(TestResponse $resposta, array $esperado): void
{
    $resposta->assertStatus(202);
    $corpo = $resposta->json();

    expect(violacoesDoSchemaFluxoOcr(schemaFluxoOcr('ProcessamentoDeOcr'), $corpo))->toBe([])
        ->and($corpo)->toBe(array_merge([
            'numero_processo' => NUMERO_FLUXO_OCR,
            'tribunal_id' => test()->tribunal->id,
        ], $esperado));

    expectInvarianteRf08FluxoOcr($corpo, documentosNoBancoFluxoOcr(NUMERO_FLUXO_OCR, test()->tribunal->id));
}

function expectInvarianteRf08FluxoOcr(array $contagens, int $documentosNoBanco): void
{
    expect($contagens['processados'] + $contagens['com_erro'])->toBeLessThanOrEqual($contagens['total'])
        ->and($contagens['total'] + $contagens['ignorados'])->toBe($documentosNoBanco);
}

/**
 * Todo aviso enviado no teste: válido contra AvisoDeProgressoDoOcr, com o
 * invariante de RF-08 no momento do envio, na URL exata de um assinante e
 * com o X-API-Token dele (RF-14).
 */
function conferirAvisosFluxoOcr(): void
{
    $schema = schemaFluxoOcr('AvisoDeProgressoDoOcr');

    expect(test()->callbacks->requisicoes)->not->toBeEmpty();

    foreach (test()->callbacks->requisicoes as $aviso) {
        // array_key_exists, e não toHaveKey: o ponto do host seria lido como caminho.
        expect(violacoesDoSchemaFluxoOcr($schema, $aviso['payload']))->toBe([])
            ->and(test()->tokensPorCallback->offsetExists($aviso['url']))->toBeTrue()
            ->and($aviso['token'])->toBe(test()->tokensPorCallback[$aviso['url']] ?? null);

        expectInvarianteRf08FluxoOcr($aviso['payload'], $aviso['documentos_no_banco']);
    }
}

/**
 * Os avisos entregues (2xx) ao callback do solicitante, na ordem.
 *
 * @return list<array<string, mixed>>
 */
function avisosEntreguesFluxoOcr(array $solicitante): array
{
    return array_values(array_map(
        fn (array $aviso) => $aviso['payload'],
        array_filter(
            test()->callbacks->requisicoes,
            fn (array $aviso) => $aviso['url'] === $solicitante['callback_url'] && $aviso['status'] < 300,
        ),
    ));
}

/**
 * Resumo dos avisos entregues: "situacao processados/com_erro", com o
 * `codigo` quando houver.
 *
 * @return list<string>
 */
function resumoDosAvisosFluxoOcr(array $solicitante): array
{
    return array_map(
        fn (array $aviso) => trim("{$aviso['situacao']} {$aviso['processados']}/{$aviso['com_erro']} ".($aviso['codigo'] ?? '')),
        avisosEntreguesFluxoOcr($solicitante),
    );
}

/**
 * Toda chamada SOAP leva o par de quem abriu o processamento, nunca o par
 * padrão nem a credencial do tribunal (RF-02 AC c).
 */
function expectSoapComOParFluxoOcr(array $requisicoes, array $dono): void
{
    expect($requisicoes)->not->toBeEmpty();

    foreach ($requisicoes as $requisicao) {
        expect($requisicao['parametros']['idConsultante'] ?? null)->toBe($dono['login_pje'])
            ->and($requisicao['parametros']['senhaConsultante'] ?? null)->toBe($dono['senha_pje']);
    }
}

/**
 * @return list<int> os documentos baixados (consulta de documento) nas requisições
 */
function downloadsFluxoOcr(array $requisicoes): array
{
    return array_values(array_map(
        fn (array $requisicao) => (int) $requisicao['parametros']['documento'],
        array_filter($requisicoes, fn (array $requisicao) => array_key_exists('documento', $requisicao['parametros'])),
    ));
}

/**
 * @param  list<string>  $esperadas  classes dos jobs que falharam, na ordem
 */
function expectFalhasDaFilaFluxoOcr(array $esperadas): void
{
    expect(array_map(fn (array $falha) => $falha['job'], test()->fila->falhas))->toBe($esperadas);
}

// ---------- schema do contrato publicado ----------

function schemaFluxoOcr(string $nome): array
{
    static $schemas = null;
    $schemas ??= Yaml::parseFile(base_path('docs/api/openapi.yaml'))['components']['schemas'];

    return $schemas[$nome];
}

/**
 * Validação do subconjunto de JSON Schema que ProcessamentoDeOcr e
 * AvisoDeProgressoDoOcr usam: `type` (object, string, integer),
 * `required`, `properties` (no `object`, chave fora do schema é violação:
 * o corpo tem exatamente as chaves do contrato), `enum`, `const`,
 * `pattern`, `minimum` e `if`/`then`. Palavra-chave desconhecida também é
 * violação, para que uma mudança no schema não passe sem ser conferida.
 *
 * @return list<string> as violações; vazia quando o valor valida
 */
function violacoesDoSchemaFluxoOcr(array $schema, mixed $valor, string $caminho = '$'): array
{
    $conhecidas = ['type', 'required', 'properties', 'enum', 'const', 'pattern', 'minimum', 'if', 'then', 'description', 'x-dependencia', 'example', 'examples'];
    $violacoes = array_map(
        fn (string $chave) => "{$caminho}: palavra-chave não suportada pelo helper: {$chave}",
        array_values(array_diff(array_keys($schema), $conhecidas)),
    );

    $tipo = $schema['type'] ?? null;
    $tipoValido = match ($tipo) {
        'object' => is_array($valor) && $valor !== [] && ! array_is_list($valor),
        'string' => is_string($valor),
        'integer' => is_int($valor),
        null => true,
        default => false,
    };

    if (! $tipoValido) {
        return [...$violacoes, "{$caminho}: esperado {$tipo}, veio ".get_debug_type($valor)];
    }

    if (array_key_exists('const', $schema) && $valor !== $schema['const']) {
        $violacoes[] = "{$caminho}: esperado ".json_encode($schema['const']);
    }

    if (isset($schema['enum']) && ! in_array($valor, $schema['enum'], true)) {
        $violacoes[] = "{$caminho}: fora do enum";
    }

    if (isset($schema['pattern']) && (! is_string($valor) || preg_match('~'.$schema['pattern'].'~u', $valor) !== 1)) {
        $violacoes[] = "{$caminho}: não casa {$schema['pattern']}";
    }

    if (isset($schema['minimum']) && (! is_int($valor) || $valor < $schema['minimum'])) {
        $violacoes[] = "{$caminho}: abaixo de {$schema['minimum']}";
    }

    if (is_array($valor)) {
        foreach ($schema['required'] ?? [] as $chave) {
            if (! array_key_exists($chave, $valor)) {
                $violacoes[] = "{$caminho}.{$chave}: obrigatório";
            }
        }

        foreach ($schema['properties'] ?? [] as $chave => $subschema) {
            if (array_key_exists($chave, $valor)) {
                array_push($violacoes, ...violacoesDoSchemaFluxoOcr($subschema, $valor[$chave], "{$caminho}.{$chave}"));
            }
        }

        if ($tipo === 'object') {
            foreach (array_diff(array_keys($valor), array_keys($schema['properties'] ?? [])) as $extra) {
                $violacoes[] = "{$caminho}.{$extra}: chave fora do schema";
            }
        }

        if (isset($schema['if']) && violacoesDoSchemaFluxoOcr($schema['if'], $valor, $caminho) === []) {
            array_push($violacoes, ...violacoesDoSchemaFluxoOcr($schema['then'] ?? [], $valor, $caminho));
        }
    }

    return $violacoes;
}

/**
 * Processamento 1 de 10 PDFs, com o dono e 2 caronas, em que o tribunal
 * recusa o par do dono a partir do 4º download (RF-10). Os downloads ficam
 * na fila até os caronas entrarem.
 *
 * @return array{dono: array, caronas: list<array>, documentos: list<int>, processamento: ProcessoOcrProcessamento, durante: array}
 */
function recusaNoQuartoDownloadFluxoOcr(): array
{
    $documentos = documentosNoMniFluxoOcr(10);
    $dono = solicitanteFluxoOcr('dono');
    $caronas = [solicitanteFluxoOcr('carona-1'), solicitanteFluxoOcr('carona-2')];
    test()->processoMni->recusarAPartirDe = [$dono['login_pje'] => 4];
    test()->fila->reter = [BaixarDocumentoParaOcrJob::class];

    $pedidos = duranteFluxoOcr(function () use ($dono, $caronas) {
        $situacao = ['total' => 10, 'processados' => 0, 'com_erro' => 0, 'ignorados' => 0, 'situacao' => 'em_andamento'];

        expect202FluxoOcr(pedirOcrFluxo($dono), $situacao);

        foreach ($caronas as $carona) {
            expect202FluxoOcr(pedirOcrFluxo($carona), $situacao);
        }
    });

    // Só a listagem saiu: os 10 downloads esperam na fila, e os caronas não chamam o MNI.
    expect($pedidos['soap'])->toHaveCount(1)
        ->and(test()->fila->retidos)->toHaveCount(10);

    $durante = duranteFluxoOcr(fn () => test()->fila->soltar());

    // Daqui em diante, os workers estão livres: cada job roda na hora.
    test()->fila->reter = [];

    return [
        'dono' => $dono,
        'caronas' => $caronas,
        'documentos' => $documentos,
        'processamento' => processamentosFluxoOcr()[0],
        'durante' => $durante,
    ];
}

// ---------- o helper do schema ----------

it('o helper do schema recusa chave fora do contrato, chave ausente, situacao fora do enum e codigo sem falhou', function () {
    $schema = schemaFluxoOcr('AvisoDeProgressoDoOcr');
    $valido = [
        'numero_processo' => NUMERO_FLUXO_OCR, 'tribunal_id' => 1, 'total' => 3, 'processados' => 1,
        'com_erro' => 0, 'ignorados' => 0, 'situacao' => 'falhou', 'codigo' => 'credencial_pje_recusada',
    ];

    expect(violacoesDoSchemaFluxoOcr($schema, $valido))->toBe([])
        ->and(violacoesDoSchemaFluxoOcr($schema, [...$valido, 'extra' => 1]))->toBe(['$.extra: chave fora do schema'])
        ->and(violacoesDoSchemaFluxoOcr($schema, array_diff_key($valido, ['total' => 0])))->toBe(['$.total: obrigatório'])
        ->and(violacoesDoSchemaFluxoOcr($schema, [...$valido, 'situacao' => 'parado']))->toContain('$.situacao: fora do enum')
        ->and(violacoesDoSchemaFluxoOcr($schema, [...$valido, 'situacao' => 'concluido']))->toBe(['$.situacao: esperado "falhou"'])
        ->and(violacoesDoSchemaFluxoOcr($schema, [...$valido, 'numero_processo' => '123']))->toBe(['$.numero_processo: não casa ^[0-9]{20}$'])
        ->and(violacoesDoSchemaFluxoOcr($schema, [...$valido, 'processados' => '1']))->toBe(['$.processados: esperado integer, veio string'])
        ->and(violacoesDoSchemaFluxoOcr($schema, [...$valido, 'com_erro' => -1]))->toBe(['$.com_erro: abaixo de 0']);
});

// ---------- fluxos ----------

it('5 documentos e 2 assinantes desde o início: 5 avisos em_andamento e 1 final concluido por assinante, na URL exata e com o token de cada um (RF-14 AC a e b, RF-15)', function () {
    $documentos = documentosNoMniFluxoOcr(5, [PRIMEIRO_DOCUMENTO_FLUXO_OCR + 50 => 'video/mp4']);
    $dono = solicitanteFluxoOcr('dono');
    $carona = solicitanteFluxoOcr('carona');
    $abertura = ['total' => 5, 'processados' => 0, 'com_erro' => 0, 'ignorados' => 1, 'situacao' => 'em_andamento'];

    $pedidos = duranteFluxoOcr(function () use ($dono, $carona, $abertura) {
        expect202FluxoOcr(pedirOcrFluxo($dono), $abertura);
        expect202FluxoOcr(pedirOcrFluxo($carona), $abertura);
    });

    // A listagem e os 5 downloads com o par do dono; o vídeo não é baixado.
    expect(downloadsFluxoOcr($pedidos['soap']))->toBe($documentos)
        ->and($pedidos['soap'])->toHaveCount(6)
        ->and($pedidos['workflow'])->toBe(array_fill(0, 5, POST_FLUXO_OCR))
        ->and($pedidos['avisos'])->toBe([]);
    expectSoapComOParFluxoOcr($pedidos['soap'], $dono);

    retornarTodosFluxoOcr($documentos);

    foreach ([$dono, $carona] as $assinante) {
        $avisos = avisosEntreguesFluxoOcr($assinante);

        expect(resumoDosAvisosFluxoOcr($assinante))->toBe([
            'em_andamento 1/0', 'em_andamento 2/0', 'em_andamento 3/0', 'em_andamento 4/0', 'em_andamento 5/0', 'concluido 5/0',
        ])->and(end($avisos))->toBe([
            'numero_processo' => NUMERO_FLUXO_OCR,
            'tribunal_id' => $this->tribunal->id,
            'total' => 5,
            'processados' => 5,
            'com_erro' => 0,
            'ignorados' => 1,
            'situacao' => 'concluido',
        ]);
    }

    expect($this->callbacks->requisicoes)->toHaveCount(12)
        ->and(processamentosFluxoOcr()[0]->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_CONCLUIDO);
    conferirAvisosFluxoOcr();
    expectFalhasDaFilaFluxoOcr([]);
});

it('carona com 4 de 10 processados: 202 com a contagem atual, 0 SOAP e 0 jobs, e o carona recebe 6 avisos em_andamento e 1 final (RF-06 AC a e b)', function () {
    $documentos = documentosNoMniFluxoOcr(10);
    $dono = solicitanteFluxoOcr('dono');
    $carona = solicitanteFluxoOcr('carona');

    $pedido = duranteFluxoOcr(fn () => expect202FluxoOcr(pedirOcrFluxo($dono), [
        'total' => 10, 'processados' => 0, 'com_erro' => 0, 'ignorados' => 0, 'situacao' => 'em_andamento',
    ]));
    retornarTodosFluxoOcr(array_slice($documentos, 0, 4));

    $carona202 = duranteFluxoOcr(fn () => expect202FluxoOcr(pedirOcrFluxo($carona), [
        'total' => 10, 'processados' => 4, 'com_erro' => 0, 'ignorados' => 0, 'situacao' => 'em_andamento',
    ]));

    expect($carona202['soap'])->toBe([])
        ->and($carona202['jobs'])->toBe([])
        ->and($carona202['workflow'])->toBe([])
        ->and($carona202['avisos'])->toBe([]);

    retornarTodosFluxoOcr(array_slice($documentos, 4));

    expect(resumoDosAvisosFluxoOcr($carona))->toBe([
        'em_andamento 5/0', 'em_andamento 6/0', 'em_andamento 7/0', 'em_andamento 8/0', 'em_andamento 9/0', 'em_andamento 10/0', 'concluido 10/0',
    ])->and(avisosEntreguesFluxoOcr($dono))->toHaveCount(11)
        ->and(last(avisosEntreguesFluxoOcr($dono))['situacao'])->toBe('concluido');

    // 100% do SOAP com o par do dono: o par do carona nunca chega ao MNI.
    expect($pedido['soap'])->toHaveCount(11)
        ->and($this->processoMni->registro->requisicoes)->toHaveCount(11);
    expectSoapComOParFluxoOcr($this->processoMni->registro->requisicoes, $dono);
    conferirAvisosFluxoOcr();
    expectFalhasDaFilaFluxoOcr([]);
});

it('1 de 5 documentos falha sempre no download do MNI: com_erro 1, processados 4 e o final concluido (RF-09 AC b, RF-15 AC a)', function () {
    $documentos = documentosNoMniFluxoOcr(5);
    $this->processoMni->falhar = [$documentos[2]];
    $dono = solicitanteFluxoOcr('dono');

    $pedido = duranteFluxoOcr(fn () => expect202FluxoOcr(pedirOcrFluxo($dono), [
        'total' => 5, 'processados' => 0, 'com_erro' => 0, 'ignorados' => 0, 'situacao' => 'em_andamento',
    ]));

    // O download que falha esgota as tentativas e vira com_erro, com aviso;
    // os outros 4 seguem ao workflow.
    expectFalhasDaFilaFluxoOcr([BaixarDocumentoParaOcrJob::class]);
    expect(downloadsFluxoOcr($pedido['soap']))->toBe($documentos)
        ->and(array_column(enviosFluxoOcr(), 'documento'))->toBe([$documentos[0], $documentos[1], $documentos[3], $documentos[4]])
        ->and(documentoFluxoOcr($documentos[2])->ocr_situacao)->toBe(ProcessoDocumento::OCR_ERRO)
        ->and(resumoDosAvisosFluxoOcr($dono))->toBe(['em_andamento 0/1']);
    expectSoapComOParFluxoOcr($pedido['soap'], $dono);

    retornarTodosFluxoOcr([$documentos[0], $documentos[1], $documentos[3], $documentos[4]]);

    expect(resumoDosAvisosFluxoOcr($dono))->toBe([
        'em_andamento 0/1', 'em_andamento 1/1', 'em_andamento 2/1', 'em_andamento 3/1', 'em_andamento 4/1', 'concluido 4/1',
    ])->and(last(avisosEntreguesFluxoOcr($dono)))->toMatchArray(['total' => 5, 'processados' => 4, 'com_erro' => 1])
        ->and(processamentosFluxoOcr()[0]->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_CONCLUIDO);
    conferirAvisosFluxoOcr();
});

it('recusa do par a partir do 4º download de 10: 0 SOAP depois dela, final falhou por papel, e um par novo abre outro processamento com os 7 pendentes (RF-10 AC, RF-15 AC b)', function () {
    ['dono' => $dono, 'caronas' => $caronas, 'documentos' => $documentos, 'processamento' => $processamento1, 'durante' => $durante]
        = recusaNoQuartoDownloadFluxoOcr();

    // 4 downloads com o par do dono e nenhum depois da recusa; 3 envios.
    expect(downloadsFluxoOcr($durante['soap']))->toBe(array_slice($documentos, 0, 4))
        ->and(array_column(enviosFluxoOcr(), 'documento'))->toBe(array_slice($documentos, 0, 3))
        ->and($processamento1->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_FALHOU)
        ->and($processamento1->codigo_falha)->toBe('credencial_pje_recusada');
    expectSoapComOParFluxoOcr($this->processoMni->registro->requisicoes, $dono);

    // Final por papel, sem aviso de progresso: o envio não é progresso.
    expect(resumoDosAvisosFluxoOcr($dono))->toBe(['falhou 0/0 credencial_pje_recusada']);
    foreach ($caronas as $carona) {
        expect(resumoDosAvisosFluxoOcr($carona))->toBe(['falhou 0/0 credencial_de_outro_pedido_recusada']);
    }

    // A recusa não conta em com_erro: o documento recusado e os seguintes ficam pendentes.
    foreach ($documentos as $i => $id) {
        expect(documentoFluxoOcr($id)->ocr_situacao)->toBe($i < 3 ? ProcessoDocumento::OCR_ENVIADO : null);
    }

    $novo = solicitanteFluxoOcr('novo');
    $pedidoNovo = duranteFluxoOcr(fn () => expect202FluxoOcr(pedirOcrFluxo($novo), [
        'total' => 10, 'processados' => 0, 'com_erro' => 0, 'ignorados' => 0, 'situacao' => 'em_andamento',
    ]));

    [, $processamento2] = processamentosFluxoOcr();

    // Os 7 pendentes vão à fila do processamento 2; os 3 aguardando retorno
    // há menos de A não entram (RF-18 item 1).
    expect(documentosDosJobsFluxoOcr($pedidoNovo['jobs'], BaixarDocumentoParaOcrJob::class))->toBe(array_slice($documentos, 3))
        ->and(collect($pedidoNovo['jobs'])->whereInstanceOf(BaixarDocumentoParaOcrJob::class)->pluck('processamentoId')->unique()->values()->all())
        ->toBe([$processamento2->id])
        ->and(documentosDosJobsFluxoOcr($pedidoNovo['jobs'], VerificarReenvioOcrJob::class))->toBe([])
        ->and($processamento2->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO)
        ->and(downloadsFluxoOcr($pedidoNovo['soap']))->toBe(array_slice($documentos, 3))
        ->and($pedidoNovo['workflow'])->toBe(array_fill(0, 7, POST_FLUXO_OCR));
    expectSoapComOParFluxoOcr($pedidoNovo['soap'], $novo);

    // Os assinantes do processamento 1 não recebem mais nada.
    expect(avisosEntreguesFluxoOcr($dono))->toHaveCount(1);
    conferirAvisosFluxoOcr();
    expectFalhasDaFilaFluxoOcr([]);
});

it('o retorno do documento 2, enviado no processamento 1 encerrado pela recusa, conta como progresso do processamento 2 e avisa só os assinantes dele (RF-18 AC a)', function () {
    ['dono' => $dono, 'caronas' => $caronas, 'documentos' => $documentos] = recusaNoQuartoDownloadFluxoOcr();
    $novo = solicitanteFluxoOcr('novo');
    $caronaNovo = solicitanteFluxoOcr('carona-novo');

    expect202FluxoOcr(pedirOcrFluxo($novo), ['total' => 10, 'processados' => 0, 'com_erro' => 0, 'ignorados' => 0, 'situacao' => 'em_andamento']);
    expect202FluxoOcr(pedirOcrFluxo($caronaNovo), ['total' => 10, 'processados' => 0, 'com_erro' => 0, 'ignorados' => 0, 'situacao' => 'em_andamento']);

    $retorno = duranteFluxoOcr(fn () => retornoDoWorkflowFluxoOcr(envioDoDocumentoFluxoOcr($documentos[1], 0))->assertOk());

    expect(documentoFluxoOcr($documentos[1])->ocr_situacao)->toBe(ProcessoDocumento::OCR_PROCESSADO)
        ->and(collect($retorno['avisos'])->pluck('url')->all())->toBe([$novo['callback_url'], $caronaNovo['callback_url']])
        ->and(resumoDosAvisosFluxoOcr($novo))->toBe(['em_andamento 1/0'])
        ->and(resumoDosAvisosFluxoOcr($caronaNovo))->toBe(['em_andamento 1/0']);

    // Os assinantes do processamento 1 ficam só com o final da recusa.
    foreach ([$dono, ...$caronas] as $assinante) {
        expect(avisosEntreguesFluxoOcr($assinante))->toHaveCount(1);
    }

    // Os retornos dos outros 2 envios do processamento 1 e dos 7 do 2 concluem o processamento 2.
    retornarTodosFluxoOcr([$documentos[0], $documentos[2], ...array_slice($documentos, 3)]);

    $resumo = resumoDosAvisosFluxoOcr($novo);
    expect($resumo)->toHaveCount(11)
        ->and(end($resumo))->toBe('concluido 10/0')
        ->and(resumoDosAvisosFluxoOcr($caronaNovo))->toBe($resumo)
        ->and(processamentosFluxoOcr()[1]->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_CONCLUIDO);
    conferirAvisosFluxoOcr();
    expectFalhasDaFilaFluxoOcr([]);
});

it('só 1 documento aguardando retorno há menos de A: 202 em_andamento sem job e sem chamada ao workflow, e o success gera 1 em_andamento e 1 concluido (RF-18 AC e, h, k)', function () {
    [$id] = documentosNoMniFluxoOcr(1);
    $primeiro = solicitanteFluxoOcr('primeiro');
    $segundo = solicitanteFluxoOcr('segundo');

    expect202FluxoOcr(pedirOcrFluxo($primeiro), ['total' => 1, 'processados' => 0, 'com_erro' => 0, 'ignorados' => 0, 'situacao' => 'em_andamento']);
    $this->workflow->jobs['job-1'] = 'failed';

    // Na rodada dos 20 min, o job antigo em failed leva ao reenvio. Na dos
    // 25, P vence sem progresso (o reenvio não é progresso) com o job novo
    // em running: falhou sem codigo.
    $rodadas = duranteFluxoOcr(fn () => rodadasFluxoOcr(1, 25));

    expect($rodadas['workflow'])->toBe(['GET /jobs/job-1', 'DELETE /jobs/job-1/chunks', POST_FLUXO_OCR, 'GET /jobs/job-2'])
        ->and($rodadas['soap'])->toBe([])
        ->and(processamentosFluxoOcr()[0]->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_FALHOU)
        ->and(resumoDosAvisosFluxoOcr($primeiro))->toBe(['falhou 0/0'])
        ->and(documentoFluxoOcr($id)->ocr_enviado_em->equalTo($this->minutoZero->addMinutes(20)))->toBeTrue();

    // Aos 26 min, o documento aguarda há 6 min (< A).
    $this->travelTo($this->minutoZero->addMinutes(26));

    $pedido = duranteFluxoOcr(fn () => expect202FluxoOcr(pedirOcrFluxo($segundo), [
        'total' => 1, 'processados' => 0, 'com_erro' => 0, 'ignorados' => 0, 'situacao' => 'em_andamento',
    ]));

    expect($pedido['jobs'])->toBe([])
        ->and($pedido['workflow'])->toBe([])
        ->and($pedido['soap'])->toHaveCount(1)
        ->and(processamentosFluxoOcr()[1]->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO);
    expectSoapComOParFluxoOcr($pedido['soap'], $segundo);

    retornoDoWorkflowFluxoOcr(envioDoDocumentoFluxoOcr($id))->assertOk();

    expect(resumoDosAvisosFluxoOcr($segundo))->toBe(['em_andamento 1/0', 'concluido 1/0'])
        ->and(resumoDosAvisosFluxoOcr($primeiro))->toBe(['falhou 0/0'])
        ->and(processamentosFluxoOcr()[1]->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_CONCLUIDO);
    conferirAvisosFluxoOcr();
    expectFalhasDaFilaFluxoOcr([]);
});

it('1 documento aguardando retorno há A + 1 min, com o job antigo em failed: o pedido seguinte faz GET, DELETE e POST desse documento, nessa ordem, e o webhook do job antigo recebe 404 com 0 avisos (RF-18 AC c)', function () {
    [$aguardando, $pendente] = documentosNoMniFluxoOcr(2);
    $dono = solicitanteFluxoOcr('dono');
    $novo = solicitanteFluxoOcr('novo');

    // O processamento 1 termina pela recusa no 2º download, com o 1º documento enviado.
    $this->processoMni->recusarAPartirDe = [$dono['login_pje'] => 2];
    expect202FluxoOcr(pedirOcrFluxo($dono), ['total' => 2, 'processados' => 0, 'com_erro' => 0, 'ignorados' => 0, 'situacao' => 'em_andamento']);

    $envioAntigo = envioDoDocumentoFluxoOcr($aguardando);
    expect($envioAntigo['job_id'])->toBe('job-1')
        ->and(processamentosFluxoOcr()[0]->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_FALHOU)
        ->and(documentoFluxoOcr($pendente)->ocr_situacao)->toBeNull();

    $this->travelTo($this->minutoZero->addMinutes(21));
    $this->workflow->jobs['job-1'] = 'failed';

    $pedido = duranteFluxoOcr(fn () => expect202FluxoOcr(pedirOcrFluxo($novo), [
        'total' => 2, 'processados' => 0, 'com_erro' => 0, 'ignorados' => 0, 'situacao' => 'em_andamento',
    ]));

    // O pendente é baixado e enviado; o aguardando passa pela consulta do
    // job antigo antes do cancelamento e do reenvio, direto do S3.
    expect($pedido['workflow'])->toBe([POST_FLUXO_OCR, 'GET /jobs/job-1', 'DELETE /jobs/job-1/chunks', POST_FLUXO_OCR])
        ->and(array_column(array_slice(enviosFluxoOcr(), -2), 'documento'))->toBe([$pendente, $aguardando])
        ->and(downloadsFluxoOcr($pedido['soap']))->toBe([$pendente]);
    expectSoapComOParFluxoOcr($pedido['soap'], $novo);

    $contagemAntes = processoFluxoOcr()->documentos()->pluck('ocr_situacao', 'id_documento')->all();

    $retornoAntigo = duranteFluxoOcr(function () use ($envioAntigo) {
        retornoDoWorkflowFluxoOcr($envioAntigo)->assertNotFound()->assertExactJson(['message' => 'Job não encontrado']);
        retornoDoWorkflowFluxoOcr($envioAntigo, 'error')->assertNotFound();
    });

    expect($retornoAntigo['avisos'])->toBe([])
        ->and(processoFluxoOcr()->documentos()->pluck('ocr_situacao', 'id_documento')->all())->toBe($contagemAntes);

    retornarTodosFluxoOcr([$pendente, $aguardando]);

    expect(resumoDosAvisosFluxoOcr($novo))->toBe(['em_andamento 1/0', 'em_andamento 2/0', 'concluido 2/0'])
        ->and(resumoDosAvisosFluxoOcr($dono))->toBe(['falhou 0/0 credencial_pje_recusada']);
    conferirAvisosFluxoOcr();
    expectFalhasDaFilaFluxoOcr([]);
});

it('depois de um falhou por P com o documento enviado 5 min depois da abertura ainda aguardando, o pedido seguinte com o job antigo em failed faz 1 GET, 1 DELETE e 1 reenvio (RF-18 AC j, LIM-02)', function () {
    [$id] = documentosNoMniFluxoOcr(1);
    $primeiro = solicitanteFluxoOcr('primeiro');
    $segundo = solicitanteFluxoOcr('segundo');
    $this->fila->reter = [BaixarDocumentoParaOcrJob::class];

    expect202FluxoOcr(pedirOcrFluxo($primeiro), ['total' => 1, 'processados' => 0, 'com_erro' => 0, 'ignorados' => 0, 'situacao' => 'em_andamento']);

    // O download espera na fila e o envio sai aos 5 min; na rodada dos 25,
    // ele chega a A na mesma rodada em que P vence.
    $antesDaRodada = function (int $minuto) {
        if ($minuto === 5) {
            test()->fila->soltar();
            test()->fila->reter = [];
        }
    };

    rodadasFluxoOcr(1, 24, $antesDaRodada);
    expect(chamadasAoWorkflowFluxoOcr())->toBe([POST_FLUXO_OCR]);

    $rodadaDoPrazo = duranteFluxoOcr(fn () => rodadasFluxoOcr(25, 25));

    expect($rodadaDoPrazo['workflow'])->toBe(['GET /jobs/job-1'])
        ->and(processamentosFluxoOcr()[0]->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_FALHOU)
        ->and(documentoFluxoOcr($id)->ocr_situacao)->toBe(ProcessoDocumento::OCR_ENVIADO)
        ->and(resumoDosAvisosFluxoOcr($primeiro))->toBe(['falhou 0/0']);

    $this->travelTo($this->minutoZero->addMinutes(26));
    $this->workflow->jobs['job-1'] = 'failed';

    $pedido = duranteFluxoOcr(fn () => expect202FluxoOcr(pedirOcrFluxo($segundo), [
        'total' => 1, 'processados' => 0, 'com_erro' => 0, 'ignorados' => 0, 'situacao' => 'em_andamento',
    ]));

    expect($pedido['workflow'])->toBe(['GET /jobs/job-1', 'DELETE /jobs/job-1/chunks', POST_FLUXO_OCR])
        ->and(envioDoDocumentoFluxoOcr($id)['job_id'])->toBe('job-2')
        ->and(downloadsFluxoOcr($pedido['soap']))->toBe([]);
    expectSoapComOParFluxoOcr($pedido['soap'], $segundo);

    retornoDoWorkflowFluxoOcr(envioDoDocumentoFluxoOcr($id))->assertOk();

    expect(resumoDosAvisosFluxoOcr($segundo))->toBe(['em_andamento 1/0', 'concluido 1/0']);
    conferirAvisosFluxoOcr();
    expectFalhasDaFilaFluxoOcr([]);
});

it('sem processamento aberto, um documento aguardando retorno com idade ≥ M e o job antigo em running: o pedido seguinte faz 1 GET, 1 DELETE e 1 POST (RF-18 AC l, o; LIM-03)', function () {
    [$id] = documentosNoMniFluxoOcr(1);
    $primeiro = solicitanteFluxoOcr('primeiro');
    $segundo = solicitanteFluxoOcr('segundo');

    expect202FluxoOcr(pedirOcrFluxo($primeiro), ['total' => 1, 'processados' => 0, 'com_erro' => 0, 'ignorados' => 0, 'situacao' => 'em_andamento']);

    // Entre A e P, o job em running é consultado a cada rodada e não é
    // reenviado; em P, o processamento termina falhou com o documento aguardando.
    $rodadas = duranteFluxoOcr(fn () => rodadasFluxoOcr(1, 25));

    expect($rodadas['workflow'])->toBe(array_fill(0, 6, 'GET /jobs/job-1'))
        ->and(processamentosFluxoOcr()[0]->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_FALHOU)
        ->and(resumoDosAvisosFluxoOcr($primeiro))->toBe(['falhou 0/0']);

    // Aos 60 min (M), ainda running: o job é tido como travado.
    $this->travelTo($this->minutoZero->addMinutes(60));

    $pedido = duranteFluxoOcr(fn () => expect202FluxoOcr(pedirOcrFluxo($segundo), [
        'total' => 1, 'processados' => 0, 'com_erro' => 0, 'ignorados' => 0, 'situacao' => 'em_andamento',
    ]));

    expect($pedido['workflow'])->toBe(['GET /jobs/job-1', 'DELETE /jobs/job-1/chunks', POST_FLUXO_OCR])
        ->and(downloadsFluxoOcr($pedido['soap']))->toBe([]);
    expectSoapComOParFluxoOcr($pedido['soap'], $segundo);

    retornoDoWorkflowFluxoOcr(envioDoDocumentoFluxoOcr($id, 0))->assertNotFound();
    retornoDoWorkflowFluxoOcr(envioDoDocumentoFluxoOcr($id))->assertOk();

    expect(resumoDosAvisosFluxoOcr($segundo))->toBe(['em_andamento 1/0', 'concluido 1/0']);
    conferirAvisosFluxoOcr();
    expectFalhasDaFilaFluxoOcr([]);
});

it('503 na 1ª tentativa do 3º aviso: o final é entregue antes da nova tentativa, que sai sem requisição, e o callback recebe 0 requisições depois do final (RF-15 AC d, RF-16)', function () {
    $documentos = documentosNoMniFluxoOcr(4);
    $dono = solicitanteFluxoOcr('dono');
    $carona = solicitanteFluxoOcr('carona');

    // O callback do dono responde 503 à 1ª tentativa do 3º aviso e 2xx às demais.
    $this->callbacks->responder = function (array $aviso, array $anteriores) use ($dono) {
        if ($aviso['url'] !== $dono['callback_url']) {
            return 200;
        }

        $chaves = array_values(array_unique(array_column(
            array_filter($anteriores, fn (array $anterior) => $anterior['url'] === $aviso['url']),
            'chave',
        )));

        return count($chaves) === 2 && ! in_array($aviso['chave'], $chaves, true) ? 503 : 200;
    };

    expect202FluxoOcr(pedirOcrFluxo($dono), ['total' => 4, 'processados' => 0, 'com_erro' => 0, 'ignorados' => 0, 'situacao' => 'em_andamento']);
    expect202FluxoOcr(pedirOcrFluxo($carona), ['total' => 4, 'processados' => 0, 'com_erro' => 0, 'ignorados' => 0, 'situacao' => 'em_andamento']);

    retornarTodosFluxoOcr($documentos);

    $requisicoesDoDono = array_values(array_filter($this->callbacks->requisicoes, fn (array $aviso) => $aviso['url'] === $dono['callback_url']));
    $terceiro = ProcessoOcrAviso::query()->where('uuid', $requisicoesDoDono[2]['chave'])->sole();

    expect(array_map(fn (array $aviso) => "{$aviso['payload']['situacao']} {$aviso['status']}", $requisicoesDoDono))->toBe([
        'em_andamento 200', 'em_andamento 200', 'em_andamento 503', 'em_andamento 200', 'concluido 200',
    ])->and($terceiro->enviado_em)->toBeNull()
        ->and($terceiro->tentativas)->toBe(1);

    // A falha de um assinante não atrasa o outro.
    expect(resumoDosAvisosFluxoOcr($carona))->toBe(['em_andamento 1/0', 'em_andamento 2/0', 'em_andamento 3/0', 'em_andamento 4/0', 'concluido 4/0']);

    // A nova tentativa do 3º aviso, depois do backoff de 10 s, sai sem enviar
    // (Q-03); um retorno repetido também não gera nada.
    $depoisDoFinal = duranteFluxoOcr(function () use ($terceiro, $documentos) {
        $this->travel(10)->seconds();
        EnviarAvisoOcrJob::dispatch($terceiro->id)->onQueue('ocr-aviso');
        retornoDoWorkflowFluxoOcr(envioDoDocumentoFluxoOcr($documentos[3]))->assertOk();
    });

    expect($depoisDoFinal['avisos'])->toBe([])
        ->and($terceiro->fresh()->enviado_em)->toBeNull()
        ->and($terceiro->fresh()->tentativas)->toBe(1);

    expectFalhasDaFilaFluxoOcr([EnviarAvisoOcrJob::class]);
    expect($this->fila->falhas[0]['erro'])->toBeInstanceOf(RequestException::class);
    conferirAvisosFluxoOcr();
});
