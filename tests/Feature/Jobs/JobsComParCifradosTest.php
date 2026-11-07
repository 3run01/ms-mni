<?php

use App\Jobs\BaixarDocumentoMNIJob;
use App\Jobs\BaixarDocumentosMNIEmLoteJob;
use App\Jobs\BaixarProcessoMNIJob;
use App\Jobs\ConsultarDadosBasicosProcessoMNIJob;
use App\Jobs\ConsultarDocumentosProcessoMNIJob;
use App\Jobs\ConsultarMovimentosProcessoMNIJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\Tribunal;
use App\Services\MNI\Intercomunicacao\ConsultarProcessosService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * RNF-01 (NC-5): os 6 jobs que já levam o par do PJe vão cifrados para a
 * fila. Cada caso despacha o job real na conexão `database`, lê
 * `jobs.payload` sem decifrar e depois roda o job pelo mesmo caminho do
 * worker (DatabaseQueue::pop + fire, isto é, CallQueuedHandler), contra o
 * MNI simulado.
 */

uses(DatabaseTransactions::class);

const LOGIN_JOBS_COM_PAR_CIFRADOS = 'login-cifra-7a1';
const SENHA_JOBS_COM_PAR_CIFRADOS = 'senha-cifra-9b2';
const FILA_JOBS_COM_PAR_CIFRADOS = 'teste-par-cifrado';
const CALLBACK_JOBS_COM_PAR_CIFRADOS = 'https://example.com/webhook-par-cifrado';

beforeEach(function () {
    // O teste lê a tabela `jobs`: a fila do teste é a do banco, e não a sync do phpunit.xml.
    config()->set('queue.default', 'database');
    Storage::fake('s3');
    // Os Consultar* notificam o callback do chamador; nada sai para a rede real.
    Http::fake(['*' => Http::response('', 200)]);
    definirCredenciaisPadrao(null, null);

    // Lote pelo caminho feliz (`consultarProcessos`): só a checagem do WSDL é
    // simulada; consultarPagina roda de verdade sobre o IntegracaoBase simulado.
    $this->partialMock(ConsultarProcessosService::class, function ($mock) {
        $mock->shouldReceive('operacaoDisponivel')->andReturn(true);
    });

    [$processo, $documentos] = criarDocumentosLote(1);
    $this->processo = $processo;
    $this->documento = $documentos->first();
    $this->tribunal = Tribunal::find($processo->tribunal_id);
    $this->mni = simularIntegracaoMni(respostaMniJobsComParCifrados($processo, $this->documento));
});

/**
 * Resposta do MNI simulado para os 3 formatos que os 6 jobs usam: a página
 * de `consultarProcessos` do lote, a consulta de 1 documento e a consulta do
 * processo (dados básicos, movimentos e lista de documentos, todos vazios).
 */
function respostaMniJobsComParCifrados(Processo $processo, ProcessoDocumento $documento): Closure
{
    return function (string $operacao, array $parametros) use ($processo, $documento) {
        if ($operacao === ConsultarProcessosService::OPERACAO) {
            return (object) [
                'sucesso' => true,
                'processos' => (object) ['processo' => (object) ['documento' => documentoMniFake($documento)]],
            ];
        }

        if (array_key_exists('documento', $parametros)) {
            return (object) [
                'sucesso' => true,
                'processo' => (object) ['documento' => documentoMniFake($documento)],
            ];
        }

        return (object) [
            'sucesso' => true,
            'processo' => (object) [
                'dadosBasicos' => (object) [
                    'numero' => $processo->numero_processo,
                    'codigoLocalidade' => '1',
                    'classeProcessual' => '7',
                    'nivelSigilo' => 0,
                    'valorCausa' => 0,
                    'polo' => [],
                ],
                'movimento' => [],
                'documento' => [],
            ],
        ];
    };
}

/**
 * Os 6 jobs, montados como os pontos de despacho reais os montam.
 */
function montarJobComParCifrado(string $classe, Tribunal $tribunal, Processo $processo, ProcessoDocumento $documento): object
{
    $login = LOGIN_JOBS_COM_PAR_CIFRADOS;
    $senha = SENHA_JOBS_COM_PAR_CIFRADOS;

    return match ($classe) {
        BaixarDocumentoMNIJob::class => new BaixarDocumentoMNIJob($documento, $login, $senha),
        BaixarDocumentosMNIEmLoteJob::class => new BaixarDocumentosMNIEmLoteJob(
            $processo->id,
            [$documento->id],
            [$documento->id => 0],
            (string) Str::uuid(),
            $login,
            $senha,
        ),
        BaixarProcessoMNIJob::class => new BaixarProcessoMNIJob($tribunal, $processo->numero_processo, $login, $senha),
        ConsultarDadosBasicosProcessoMNIJob::class,
        ConsultarDocumentosProcessoMNIJob::class,
        ConsultarMovimentosProcessoMNIJob::class => new $classe(
            $tribunal->id,
            $processo->numero_processo,
            $login,
            $senha,
            CALLBACK_JOBS_COM_PAR_CIFRADOS,
            'tok-par-cifrado',
        ),
    };
}

function despacharJobComParCifrado(object $job): object
{
    dispatch($job->onConnection('database')->onQueue(FILA_JOBS_COM_PAR_CIFRADOS));

    return DB::table('jobs')->where('queue', FILA_JOBS_COM_PAR_CIFRADOS)->sole();
}

/**
 * O par guardado no job, lido de dentro do objeto: em 2 dos 6 jobs as
 * propriedades são privadas.
 *
 * @return array{0: mixed, 1: mixed}
 */
function parGuardadoNoJob(object $job): array
{
    return (fn () => [$this->login_pje, $this->senha_pje])->call($job);
}

/**
 * Roda o job da fila como o worker roda: pop na conexão `database` e fire,
 * que passa pelo CallQueuedHandler (decifra, desserializa, handle()).
 */
function processarJobDaFilaComParCifrado(): void
{
    $job = Queue::connection('database')->pop(FILA_JOBS_COM_PAR_CIFRADOS);

    expect($job)->not->toBeNull();

    $job->fire();

    expect($job->hasFailed())->toBeFalse()
        ->and(DB::table('jobs')->where('queue', FILA_JOBS_COM_PAR_CIFRADOS)->count())->toBe(0);
}

function expectMniChamadoComParCifrado(stdClass $mni): void
{
    expect($mni->requisicoes)->not->toBeEmpty();

    foreach ($mni->requisicoes as $requisicao) {
        expect($requisicao['parametros']['idConsultante'])->toBe(LOGIN_JOBS_COM_PAR_CIFRADOS)
            ->and($requisicao['parametros']['senhaConsultante'])->toBe(SENHA_JOBS_COM_PAR_CIFRADOS);
    }
}

dataset('jobs que levam o par', [
    'BaixarDocumentoMNIJob' => [BaixarDocumentoMNIJob::class],
    'BaixarDocumentosMNIEmLoteJob' => [BaixarDocumentosMNIEmLoteJob::class],
    'BaixarProcessoMNIJob' => [BaixarProcessoMNIJob::class],
    'ConsultarDadosBasicosProcessoMNIJob' => [ConsultarDadosBasicosProcessoMNIJob::class],
    'ConsultarDocumentosProcessoMNIJob' => [ConsultarDocumentosProcessoMNIJob::class],
    'ConsultarMovimentosProcessoMNIJob' => [ConsultarMovimentosProcessoMNIJob::class],
]);

it('o job implementa ShouldBeEncrypted', function (string $classe) {
    expect(is_subclass_of($classe, ShouldBeEncrypted::class))->toBeTrue();
})->with('jobs que levam o par');

it('BaixarDocumentoMNIJob continua ShouldBeUnique por documento', function () {
    $job = new BaixarDocumentoMNIJob($this->documento, LOGIN_JOBS_COM_PAR_CIFRADOS, SENHA_JOBS_COM_PAR_CIFRADOS);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe((string) $this->documento->id);
});

it('o login e a senha do construtor são parâmetros sensíveis', function (string $classe) {
    $parametros = collect((new ReflectionMethod($classe, '__construct'))->getParameters())
        ->keyBy(fn (ReflectionParameter $parametro) => $parametro->getName());

    foreach (['login_pje', 'senha_pje'] as $nome) {
        expect($parametros->get($nome)?->getAttributes(SensitiveParameter::class))->toHaveCount(1, "{$classe}::\${$nome}");
    }
})->with('jobs que levam o par');

it('payload do job na fila não contém o par', function (string $classe) {
    $linha = despacharJobComParCifrado(montarJobComParCifrado($classe, $this->tribunal, $this->processo, $this->documento));

    $payload = json_decode($linha->payload, true);

    expect($linha->payload)->not->toContain(LOGIN_JOBS_COM_PAR_CIFRADOS)
        ->and($linha->payload)->not->toContain(SENHA_JOBS_COM_PAR_CIFRADOS)
        ->and($payload['data']['commandName'])->toBe($classe)
        ->and($payload['data']['command'])->not->toStartWith('O:');
})->with('jobs que levam o par');

it('data.command decifrado desserializa no mesmo job com o par', function (string $classe) {
    $linha = despacharJobComParCifrado(montarJobComParCifrado($classe, $this->tribunal, $this->processo, $this->documento));

    // Mesmo caminho do CallQueuedHandler::getCommand: decifra e desserializa.
    $comando = Crypt::decrypt(json_decode($linha->payload, true)['data']['command']);
    $job = unserialize($comando);

    expect($comando)->toStartWith('O:')
        ->and($job)->toBeInstanceOf($classe)
        ->and(parGuardadoNoJob($job))->toBe([LOGIN_JOBS_COM_PAR_CIFRADOS, SENHA_JOBS_COM_PAR_CIFRADOS]);
})->with('jobs que levam o par');

it('job decifrado chama o MNI com o mesmo par', function (string $classe) {
    despacharJobComParCifrado(montarJobComParCifrado($classe, $this->tribunal, $this->processo, $this->documento));

    processarJobDaFilaComParCifrado();

    expectMniChamadoComParCifrado($this->mni);
})->with('jobs que levam o par');

it('payload antigo sem cifra continua processável', function (string $classe) {
    $linha = despacharJobComParCifrado(montarJobComParCifrado($classe, $this->tribunal, $this->processo, $this->documento));

    // Payload como o de antes do deploy: `command` serializado em texto claro.
    $payload = json_decode($linha->payload, true);
    $payload['data']['command'] = Crypt::decrypt($payload['data']['command']);
    DB::table('jobs')->where('id', $linha->id)->update(['payload' => json_encode($payload)]);

    expect(DB::table('jobs')->where('id', $linha->id)->value('payload'))->toContain('"command":"O:');

    processarJobDaFilaComParCifrado();

    expectMniChamadoComParCifrado($this->mni);
})->with('jobs que levam o par');

it('os downloads executados pela fila gravam o documento no S3', function (string $classe) {
    despacharJobComParCifrado(montarJobComParCifrado($classe, $this->tribunal, $this->processo, $this->documento));

    processarJobDaFilaComParCifrado();

    $documento = $this->documento->fresh();
    expect($documento->status)->toBe(ProcessoDocumento::STATUS_BAIXADO);
    Storage::disk('s3')->assertExists($documento->path);
})->with([
    'BaixarDocumentoMNIJob' => [BaixarDocumentoMNIJob::class],
    'BaixarDocumentosMNIEmLoteJob' => [BaixarDocumentosMNIEmLoteJob::class],
]);

it('BaixarProcessoMNIJob executado pela fila faz as 3 consultas com o par', function () {
    // O handle() engole as exceções: as 3 chamadas provam que ele foi até o fim.
    despacharJobComParCifrado(montarJobComParCifrado(BaixarProcessoMNIJob::class, $this->tribunal, $this->processo, $this->documento));

    processarJobDaFilaComParCifrado();

    expect($this->mni->requisicoes)->toHaveCount(3);
    expectMniChamadoComParCifrado($this->mni);
});

it('os Consultar* executados pela fila notificam o callback sem o par', function (string $classe) {
    despacharJobComParCifrado(montarJobComParCifrado($classe, $this->tribunal, $this->processo, $this->documento));

    processarJobDaFilaComParCifrado();

    Http::assertSent(fn ($requisicao) => $requisicao->url() === CALLBACK_JOBS_COM_PAR_CIFRADOS
        && ! str_contains($requisicao->body(), LOGIN_JOBS_COM_PAR_CIFRADOS)
        && ! str_contains($requisicao->body(), SENHA_JOBS_COM_PAR_CIFRADOS));
})->with([
    'ConsultarDadosBasicosProcessoMNIJob' => [ConsultarDadosBasicosProcessoMNIJob::class],
    'ConsultarDocumentosProcessoMNIJob' => [ConsultarDocumentosProcessoMNIJob::class],
    'ConsultarMovimentosProcessoMNIJob' => [ConsultarMovimentosProcessoMNIJob::class],
]);
