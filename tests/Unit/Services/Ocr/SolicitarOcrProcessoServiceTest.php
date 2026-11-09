<?php

use App\Exceptions\ConfiguracaoOcrIncompletaException;
use App\Exceptions\CredencialPjeRecusadaException;
use App\Exceptions\EsperaDaTravaOcrEsgotadaException;
use App\Exceptions\MNIException;
use App\Exceptions\ProcessoNaoEncontradoNoTribunalException;
use App\Jobs\BaixarDocumentoParaOcrJob;
use App\Jobs\EnviarDocumentoAoOcrJob;
use App\Jobs\VerificarReenvioOcrJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\ProcessoOcrAssinante;
use App\Models\ProcessoOcrAviso;
use App\Models\ProcessoOcrProcessamento;
use App\Models\Tribunal;
use App\Services\Ocr\AvisarAssinantesOcrService;
use App\Services\Ocr\ContagemOcrService;
use App\Services\Ocr\DadosSolicitacaoOcr;
use App\Services\Ocr\ProcessamentoOcrService;
use App\Services\Ocr\ResultadoSolicitacaoOcr;
use App\Services\Ocr\SolicitarOcrProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Pedido de OCR (T19; RF-04, RF-05, RF-06, RF-07, RF-18 itens 1, 3 e 4,
 * RF-02 AC c, D-08): trava da decisão por linha e número, carona sem SOAP,
 * listagem completa só na linha pedida, devolução dos `com_erro` e o
 * despacho dos jobs depois do commit.
 */

uses(DatabaseTransactions::class);

const NUMERO_SOL_OCR = '12345674220268260100';
const LOGIN_DONO_SOL_OCR = 'login-dono-7c4';
const SENHA_DONO_SOL_OCR = 'senha-dono-2f9';
const LOGIN_CARONA_SOL_OCR = 'login-carona-5e1';
const SENHA_CARONA_SOL_OCR = 'senha-carona-8b3';
const TOKEN_CALLBACK_DONO_SOL_OCR = 'tok-callback-dono-91a';
const TOKEN_CALLBACK_CARONA_SOL_OCR = 'tok-callback-carona-47d';
const LOGIN_PADRAO_SOL_OCR = 'login-padrao-sol-ocr';
const SENHA_PADRAO_SOL_OCR = 'senha-padrao-sol-ocr';
const URL_LINHA_SOL_OCR = 'https://tjap-1grau.example/mni/consultar-processo';
const URL_OUTRA_LINHA_SOL_OCR = 'https://tjap-2grau.example/mni/consultar-processo';
const RECUSA_SOL_OCR = 'Erro ao realizar login via MNI. exception invoking: loginFailed';

beforeEach(function () {
    Queue::fake();

    config()->set('services.n8n_ocr.url', 'https://n8n.example/webhook/juridico-ocr');
    config()->set('services.n8n_ocr.token', 'token-workflow-sol-ocr');
    config()->set('services.n8n_ocr.espera_da_trava_segundos', 45);

    // O par padrão existe, mas o OCR nunca o usa (RF-02).
    definirCredenciaisPadrao(LOGIN_PADRAO_SOL_OCR, SENHA_PADRAO_SOL_OCR);

    // Duas linhas ativas da mesma sigla (DEP-02): só a pedida é tratada.
    $this->tribunal = Tribunal::factory()->create([
        'sigla' => 'TJAP',
        'grau' => '1',
        'login' => 'login-do-tribunal',
        'password' => Crypt::encrypt('{SENHA}'),
        'url_webservice_mni_consultar_processo' => URL_LINHA_SOL_OCR,
        'ativo' => true,
    ]);
    $this->outraLinha = Tribunal::factory()->create([
        'sigla' => 'TJAP',
        'grau' => '2',
        'login' => 'login-do-tribunal',
        'password' => Crypt::encrypt('{SENHA}'),
        'url_webservice_mni_consultar_processo' => URL_OUTRA_LINHA_SOL_OCR,
        'ativo' => true,
    ]);
});

function dadosSolOcr(Tribunal $tribunal, string $papel = 'dono'): DadosSolicitacaoOcr
{
    $dono = $papel === 'dono';

    return new DadosSolicitacaoOcr(
        numeroProcesso: NUMERO_SOL_OCR,
        tribunalId: $tribunal->id,
        login: $dono ? LOGIN_DONO_SOL_OCR : LOGIN_CARONA_SOL_OCR,
        senha: $dono ? SENHA_DONO_SOL_OCR : SENHA_CARONA_SOL_OCR,
        callbackUrl: "https://sistema.example/mni/processamentos/{$papel}/progresso?signature=sig-{$papel}",
        callbackToken: $dono ? TOKEN_CALLBACK_DONO_SOL_OCR : TOKEN_CALLBACK_CARONA_SOL_OCR,
        apiTokenId: criarTokenApi('tk-sol-ocr-'.$papel.'-'.Str::random(6))->id,
    );
}

function solicitarSolOcr(DadosSolicitacaoOcr $dados): ResultadoSolicitacaoOcr
{
    return app(SolicitarOcrProcessoService::class)->solicitar($dados);
}

/**
 * Processo já gravado na linha, com um documento por item: a `ocr_situacao`
 * (string ou null) ou os atributos do documento.
 *
 * @param  list<string|array<string, mixed>|null>  $documentos
 * @return array{0: Processo, 1: Collection<int, ProcessoDocumento>}
 */
function processoSolOcr(Tribunal $tribunal, array $documentos): array
{
    $processo = Processo::factory()->create([
        'tribunal_id' => $tribunal->id,
        'numero_processo' => NUMERO_SOL_OCR,
    ]);

    if ($documentos === []) {
        return [$processo, collect()];
    }

    return criarDocumentosLote(
        array_map(fn ($documento) => is_array($documento) ? $documento : ['ocr_situacao' => $documento], $documentos),
        $processo,
    );
}

/**
 * consultarProcesso com sucesso, cru como o MNI devolve.
 *
 * @param  list<object>  $documentos
 */
function retornoMniSolOcr(array $documentos): object
{
    return (object) [
        'sucesso' => true,
        'processo' => (object) [
            'dadosBasicos' => (object) [
                'numero' => NUMERO_SOL_OCR,
                'codigoLocalidade' => '1',
                'classeProcessual' => '7',
                'nivelSigilo' => 0,
                'valorCausa' => 1500,
            ],
            'movimento' => [],
            'documento' => $documentos,
        ],
    ];
}

/**
 * O MNI devolve os documentos já gravados mais os `$novos`
 * (`[id_documento => mimetype]`).
 *
 * @param  iterable<ProcessoDocumento>  $gravados
 * @param  array<int, string>  $novos
 */
function mniSolOcr(iterable $gravados = [], array $novos = []): stdClass
{
    $documentos = [];

    foreach ($gravados as $documento) {
        $documentos[] = documentoMniFake($documento);
    }

    foreach ($novos as $idDocumento => $mimetype) {
        $documentos[] = documentoMniFake($idDocumento, ['mimetype' => $mimetype]);
    }

    return simularIntegracaoMni(fn () => retornoMniSolOcr($documentos));
}

/**
 * Linhas do OCR dos processos de uma linha: as tabelas podem ter dados de
 * desenvolvimento, por isso a contagem é sempre por tribunal.
 *
 * @return array{processamentos: int, abertos: int, assinantes: int}
 */
function linhasOcrSolOcr(Tribunal $tribunal): array
{
    $processos = Processo::query()->toBase()->where('tribunal_id', $tribunal->id)->pluck('id');
    $processamentos = ProcessoOcrProcessamento::query()->whereIn('processo_id', $processos);

    return [
        'processamentos' => (clone $processamentos)->count(),
        'abertos' => (clone $processamentos)->emAndamento()->count(),
        'assinantes' => ProcessoOcrAssinante::query()
            ->whereIn('processamento_id', (clone $processamentos)->pluck('id'))
            ->count(),
    ];
}

/**
 * @return list<int>
 */
function idsDosJobsSolOcr(string $classe): array
{
    return Queue::pushed($classe)->map(fn ($job) => $job->documentoId)->sort()->values()->all();
}

/**
 * @return array{0: string, 1: string}
 */
function parDoJobDownloadSolOcr(BaixarDocumentoParaOcrJob $job): array
{
    return (fn () => [$this->login, $this->senha])->call($job);
}

/**
 * RF-08: `processados + com_erro ≤ total` e `total + ignorados` = documentos
 * do processo na linha no banco.
 */
function conferirInvarianteSolOcr(ResultadoSolicitacaoOcr $resultado, Tribunal $tribunal): void
{
    $processo = Processo::query()->toBase()
        ->where('tribunal_id', $tribunal->id)
        ->where('numero_processo', NUMERO_SOL_OCR)
        ->value('id');

    expect($resultado->processados + $resultado->comErro)->toBeLessThanOrEqual($resultado->total)
        ->and($resultado->total + $resultado->ignorados)
        ->toBe(ProcessoDocumento::where('processo_id', $processo)->count());
}

/**
 * Texto de todas as linhas ligadas ao processo: o processo, os documentos e
 * as tabelas do OCR.
 */
function linhasDoProcessoEmTextoSolOcr(Processo $processo): string
{
    $processamentos = DB::table('processo_ocr_processamentos')->where('processo_id', $processo->id)->pluck('id');
    $assinantes = DB::table('processo_ocr_assinantes')->whereIn('processamento_id', $processamentos)->pluck('id');

    return json_encode([
        DB::table('processos')->where('id', $processo->id)->get(),
        DB::table('processo_documentos')->where('processo_id', $processo->id)->get(),
        DB::table('processo_ocr_processamentos')->where('processo_id', $processo->id)->get(),
        DB::table('processo_ocr_assinantes')->whereIn('processamento_id', $processamentos)->get(),
        DB::table('processo_ocr_avisos')->whereIn('assinante_id', $assinantes)->get(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function payloadsDosJobsSolOcr(): string
{
    return collect(Queue::pushedJobs())
        ->flatten(1)
        ->map(fn (array $item) => serialize($item['job']))
        ->implode("\n");
}

it('abre o processamento: 3 PDF, HTML, PNG, MP4 e MP3 → total 5, ignorados 2, em_andamento e 5 downloads com o par do pedido', function () {
    $mni = mniSolOcr([], [
        9400001 => 'application/pdf',
        9400002 => 'application/pdf',
        9400003 => 'application/pdf',
        9400004 => 'text/html',
        9400005 => 'image/png',
        9400006 => 'video/mp4',
        9400007 => 'audio/mpeg',
    ]);

    $dados = dadosSolOcr($this->tribunal);
    $resultado = solicitarSolOcr($dados);

    expect($resultado->paraCorpo())->toBe([
        'numero_processo' => NUMERO_SOL_OCR,
        'tribunal_id' => $this->tribunal->id,
        'total' => 5,
        'processados' => 0,
        'com_erro' => 0,
        'ignorados' => 2,
        'situacao' => 'em_andamento',
    ])->and($resultado->carona)->toBeFalse();
    conferirInvarianteSolOcr($resultado, $this->tribunal);

    $processo = Processo::where('tribunal_id', $this->tribunal->id)->where('numero_processo', NUMERO_SOL_OCR)->firstOrFail();
    $legiveis = ProcessoDocumento::where('processo_id', $processo->id)
        ->whereIn('id_documento', [9400001, 9400002, 9400003, 9400004, 9400005])
        ->orderBy('id')->pluck('id')->all();
    $videoEAudio = ProcessoDocumento::where('processo_id', $processo->id)
        ->whereIn('id_documento', [9400006, 9400007])->pluck('id')->all();

    // 5 downloads, 0 para o vídeo e o áudio, todos na fila do OCR.
    Queue::assertPushed(BaixarDocumentoParaOcrJob::class, 5);
    Queue::assertPushedOn(BaixarDocumentoParaOcrJob::FILA, BaixarDocumentoParaOcrJob::class);
    Queue::assertNotPushed(EnviarDocumentoAoOcrJob::class);
    Queue::assertNotPushed(VerificarReenvioOcrJob::class);

    expect(idsDosJobsSolOcr(BaixarDocumentoParaOcrJob::class))->toBe($legiveis)
        ->and(array_intersect(idsDosJobsSolOcr(BaixarDocumentoParaOcrJob::class), $videoEAudio))->toBe([]);

    // RF-02 AC c: os downloads levam o par do pedido que abriu o processamento.
    Queue::assertPushed(BaixarDocumentoParaOcrJob::class, fn (BaixarDocumentoParaOcrJob $job) => $job->processamentoId === $resultado->processamentoId
        && parDoJobDownloadSolOcr($job) === [LOGIN_DONO_SOL_OCR, SENHA_DONO_SOL_OCR]);
    expect(Queue::pushed(BaixarDocumentoParaOcrJob::class)->filter(
        fn (BaixarDocumentoParaOcrJob $job) => parDoJobDownloadSolOcr($job) !== [LOGIN_DONO_SOL_OCR, SENHA_DONO_SOL_OCR]
    ))->toHaveCount(0);

    // 1 consulta, na linha pedida, com o par do pedido e sem recorte de data.
    expect($mni->requisicoes)->toHaveCount(1)
        ->and($mni->urls)->toBe([URL_LINHA_SOL_OCR])
        ->and($mni->requisicoes[0]['operacao'])->toBe('consultarProcesso')
        ->and($mni->requisicoes[0]['parametros'])->toMatchArray([
            'idConsultante' => LOGIN_DONO_SOL_OCR,
            'senhaConsultante' => SENHA_DONO_SOL_OCR,
            'incluirDocumentos' => true,
        ])
        ->and($mni->requisicoes[0]['parametros']['dataReferencia'])->toBeNull();

    // O processamento e o assinante dono, só com o callback.
    $processamento = ProcessoOcrProcessamento::findOrFail($resultado->processamentoId);
    $assinantes = ProcessoOcrAssinante::where('processamento_id', $processamento->id)->get();

    expect($processamento->processo_id)->toBe($processo->id)
        ->and($processamento->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO)
        ->and($processamento->aberto_em)->not->toBeNull()
        ->and($processamento->ultimo_progresso_em)->toBeNull()
        ->and($assinantes)->toHaveCount(1)
        ->and($assinantes->first()->dono)->toBeTrue()
        ->and($assinantes->first()->api_token_id)->toBe($dados->apiTokenId)
        ->and($assinantes->first()->callback_url)->toBe($dados->callbackUrl)
        ->and($assinantes->first()->callback_token)->toBe(TOKEN_CALLBACK_DONO_SOL_OCR);

    // O par do pedido não fica em tabela nenhuma, e nenhum aviso sai na abertura.
    $linhas = linhasDoProcessoEmTextoSolOcr($processo);

    expect($linhas)->not->toContain(LOGIN_DONO_SOL_OCR)
        ->and($linhas)->not->toContain(SENHA_DONO_SOL_OCR)
        ->and(ProcessoOcrAviso::whereIn('assinante_id', $assinantes->pluck('id'))->count())->toBe(0);
});

it('processa só a linha tribunal_id: 0 SOAP e 0 documentos enfileirados da outra linha da mesma sigla', function () {
    // A outra linha tem o mesmo número, com documentos pendentes.
    [, $daOutraLinha] = processoSolOcr($this->outraLinha, [null, null]);

    $mni = mniSolOcr([], [9400101 => 'application/pdf']);

    $resultado = solicitarSolOcr(dadosSolOcr($this->tribunal));

    expect($resultado->total)->toBe(1)
        ->and($mni->urls)->toBe([URL_LINHA_SOL_OCR])
        ->and($mni->urls)->not->toContain(URL_OUTRA_LINHA_SOL_OCR)
        ->and(array_intersect(idsDosJobsSolOcr(BaixarDocumentoParaOcrJob::class), $daOutraLinha->pluck('id')->all()))->toBe([])
        ->and(linhasOcrSolOcr($this->outraLinha)['processamentos'])->toBe(0);

    Queue::assertPushed(BaixarDocumentoParaOcrJob::class, 1);
});

it('a falha da consulta sobe com a exceção respectiva, sem processamento, assinante nem job', function (Closure $responder, string $excecaoEsperada) {
    $mni = simularIntegracaoMni($responder);

    $excecao = null;

    try {
        solicitarSolOcr(dadosSolOcr($this->tribunal));
    } catch (Throwable $e) {
        $excecao = $e;
    }

    expect($excecao)->not->toBeNull()
        ->and(get_class($excecao))->toBe($excecaoEsperada)
        ->and($mni->requisicoes)->toHaveCount(1)
        ->and(linhasOcrSolOcr($this->tribunal))->toBe(['processamentos' => 0, 'abertos' => 0, 'assinantes' => 0]);

    Queue::assertNothingPushed();

    // A trava foi solta: o pedido seguinte do mesmo processo não espera.
    $trava = Cache::lock(SolicitarOcrProcessoService::chaveDaTrava($this->tribunal->id, NUMERO_SOL_OCR), 10);
    expect($trava->get())->toBeTrue();
    $trava->release();
})->with([
    'recusa do par' => [
        fn () => (object) ['sucesso' => false, 'mensagem' => RECUSA_SOL_OCR],
        CredencialPjeRecusadaException::class,
    ],
    'processo inexistente' => [
        fn () => (object) ['sucesso' => false, 'mensagem' => 'Processo de número '.NUMERO_SOL_OCR.' não encontrado!'],
        ProcessoNaoEncontradoNoTribunalException::class,
    ],
    'outra falha' => [
        fn () => (object) ['sucesso' => false, 'mensagem' => 'Serviço temporariamente indisponível'],
        MNIException::class,
    ],
]);

it('a recusa com processo já gravado também não abre nada', function () {
    processoSolOcr($this->tribunal, [null, ProcessoDocumento::OCR_ERRO]);
    simularIntegracaoMni(fn () => throw new SoapFault('Server', RECUSA_SOL_OCR));

    expect(fn () => solicitarSolOcr(dadosSolOcr($this->tribunal)))->toThrow(CredencialPjeRecusadaException::class)
        ->and(linhasOcrSolOcr($this->tribunal))->toBe(['processamentos' => 0, 'abertos' => 0, 'assinantes' => 0]);

    // O `com_erro` só volta a pendente quando o processamento abre (NC-3).
    expect(ProcessoDocumento::whereIn('processo_id', Processo::where('tribunal_id', $this->tribunal->id)->pluck('id'))
        ->where('ocr_situacao', ProcessoDocumento::OCR_ERRO)->count())->toBe(1);

    Queue::assertNothingPushed();
});

it('carona: contagens atuais, 0 SOAP, 0 jobs e o par da carona em 0 linhas e 0 payloads', function () {
    // Processamento de 10 documentos, 4 já processados, aberto por outro pedido.
    [$processo] = processoSolOcr($this->tribunal, [
        ProcessoDocumento::OCR_PROCESSADO, ProcessoDocumento::OCR_PROCESSADO,
        ProcessoDocumento::OCR_PROCESSADO, ProcessoDocumento::OCR_PROCESSADO,
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-sol-1', 'ocr_enviado_em' => now()->subMinutes(2)],
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-sol-2', 'ocr_enviado_em' => now()->subMinutes(2)],
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-sol-3', 'ocr_enviado_em' => now()->subMinutes(30)],
        ProcessoDocumento::OCR_PENDENTE, null, null,
    ]);
    $aberto = ProcessoOcrProcessamento::create(['processo_id' => $processo->id]);
    ProcessoOcrAssinante::create([
        'processamento_id' => $aberto->id,
        'dono' => true,
        'callback_url' => 'https://sistema.example/mni/processamentos/dono/progresso',
        'callback_token' => TOKEN_CALLBACK_DONO_SOL_OCR,
    ]);

    $mni = mniSolOcr();

    $dados = dadosSolOcr($this->tribunal, 'carona');
    $resultado = solicitarSolOcr($dados);

    expect($resultado->paraCorpo())->toBe([
        'numero_processo' => NUMERO_SOL_OCR,
        'tribunal_id' => $this->tribunal->id,
        'total' => 10,
        'processados' => 4,
        'com_erro' => 0,
        'ignorados' => 0,
        'situacao' => 'em_andamento',
    ])
        ->and($resultado->carona)->toBeTrue()
        ->and($resultado->processamentoId)->toBe($aberto->id)
        ->and($mni->requisicoes)->toHaveCount(0);
    conferirInvarianteSolOcr($resultado, $this->tribunal);

    Queue::assertNothingPushed();

    // O carona entra como assinante, só com o callback.
    $carona = ProcessoOcrAssinante::where('processamento_id', $aberto->id)->where('dono', false)->sole();

    expect($carona->callback_url)->toBe($dados->callbackUrl)
        ->and($carona->callback_token)->toBe(TOKEN_CALLBACK_CARONA_SOL_OCR)
        ->and($carona->api_token_id)->toBe($dados->apiTokenId)
        ->and(linhasOcrSolOcr($this->tribunal))->toBe(['processamentos' => 1, 'abertos' => 1, 'assinantes' => 2]);

    // O documento enviado há mais de A não é tocado pelo carona.
    expect(ProcessoDocumento::where('processo_id', $processo->id)->where('ocr_job_id', 'job-sol-3')->value('ocr_situacao'))
        ->toBe(ProcessoDocumento::OCR_ENVIADO);

    $linhas = linhasDoProcessoEmTextoSolOcr($processo);
    $payloads = payloadsDosJobsSolOcr();

    foreach ([LOGIN_CARONA_SOL_OCR, SENHA_CARONA_SOL_OCR] as $segredo) {
        expect($linhas)->not->toContain($segredo)
            ->and($payloads)->not->toContain($segredo);
    }
});

it('carona com o mesmo callback reusa o assinante pelo hash', function () {
    [$processo] = processoSolOcr($this->tribunal, [null, ProcessoDocumento::OCR_PROCESSADO]);
    $aberto = ProcessoOcrProcessamento::create(['processo_id' => $processo->id]);

    $dados = dadosSolOcr($this->tribunal, 'carona');
    $existente = ProcessoOcrAssinante::create([
        'processamento_id' => $aberto->id,
        'dono' => true,
        'callback_url' => $dados->callbackUrl,
        'callback_token' => $dados->callbackToken,
    ]);

    $mni = mniSolOcr();

    $resultado = solicitarSolOcr($dados);

    expect($resultado->carona)->toBeTrue()
        ->and($resultado->total)->toBe(2)
        ->and($resultado->processados)->toBe(1)
        ->and($mni->requisicoes)->toHaveCount(0)
        ->and(ProcessoOcrAssinante::where('processamento_id', $aberto->id)->pluck('id')->all())->toBe([$existente->id])
        ->and($existente->fresh()->dono)->toBeTrue();

    Queue::assertNothingPushed();
});

it('2 pedidos em sequência: 1 processamento, 1 consultarProcesso e os jobs só do 1º', function () {
    $mni = mniSolOcr([], [9400201 => 'application/pdf', 9400202 => 'image/jpeg']);

    $primeiro = solicitarSolOcr(dadosSolOcr($this->tribunal));
    $segundo = solicitarSolOcr(dadosSolOcr($this->tribunal, 'carona'));

    expect($primeiro->carona)->toBeFalse()
        ->and($segundo->carona)->toBeTrue()
        ->and($segundo->processamentoId)->toBe($primeiro->processamentoId)
        ->and($segundo->paraCorpo())->toBe($primeiro->paraCorpo())
        ->and(collect($mni->requisicoes)->where('operacao', 'consultarProcesso'))->toHaveCount(1)
        ->and(linhasOcrSolOcr($this->tribunal))->toBe(['processamentos' => 1, 'abertos' => 1, 'assinantes' => 2]);

    Queue::assertPushed(BaixarDocumentoParaOcrJob::class, 2);
    expect(payloadsDosJobsSolOcr())->not->toContain(LOGIN_CARONA_SOL_OCR)
        ->and(payloadsDosJobsSolOcr())->not->toContain(SENHA_CARONA_SOL_OCR);
});

it('com a trava presa por outro pedido e W = 1, desiste em menos de 2 s, com 0 SOAP e 0 assinantes', function () {
    config()->set('services.n8n_ocr.espera_da_trava_segundos', 1);

    [$processo] = processoSolOcr($this->tribunal, [null]);
    $mni = mniSolOcr([], [9400301 => 'application/pdf']);

    $outroPedido = Cache::lock(SolicitarOcrProcessoService::chaveDaTrava($this->tribunal->id, NUMERO_SOL_OCR), 600);
    expect($outroPedido->get())->toBeTrue();

    $inicio = microtime(true);
    $excecao = null;

    try {
        solicitarSolOcr(dadosSolOcr($this->tribunal));
    } catch (Throwable $e) {
        $excecao = $e;
    }

    $duracao = microtime(true) - $inicio;
    $outroPedido->release();

    expect($excecao)->toBeInstanceOf(EsperaDaTravaOcrEsgotadaException::class)
        ->and($excecao->esperaSegundos)->toBe(1)
        ->and($excecao->getMessage())->not->toContain(LOGIN_DONO_SOL_OCR)
        ->and($excecao->getMessage())->not->toContain(SENHA_DONO_SOL_OCR)
        ->and($duracao)->toBeLessThan(2.0)
        ->and($mni->requisicoes)->toHaveCount(0)
        ->and(linhasOcrSolOcr($this->tribunal))->toBe(['processamentos' => 0, 'abertos' => 0, 'assinantes' => 0]);

    Queue::assertNothingPushed();
});

it('a trava é por linha e número: a de outro processo não segura este pedido', function () {
    config()->set('services.n8n_ocr.espera_da_trava_segundos', 1);

    $outraTrava = Cache::lock(SolicitarOcrProcessoService::chaveDaTrava($this->outraLinha->id, NUMERO_SOL_OCR), 600);
    expect($outraTrava->get())->toBeTrue();

    mniSolOcr([], [9400351 => 'application/pdf']);

    expect(solicitarSolOcr(dadosSolOcr($this->tribunal))->situacao)->toBe('em_andamento');

    $outraTrava->release();
});

it('sem a base ou o token do workflow, recusa antes da trava e de qualquer SOAP', function (string $chave, mixed $valor) {
    config()->set($chave, $valor);
    $mni = mniSolOcr([], [9400401 => 'application/pdf']);

    $excecao = null;

    try {
        solicitarSolOcr(dadosSolOcr($this->tribunal));
    } catch (Throwable $e) {
        $excecao = $e;
    }

    expect($excecao)->toBeInstanceOf(ConfiguracaoOcrIncompletaException::class)
        ->and($excecao->chavesAusentes)->toBe([$chave])
        ->and($mni->requisicoes)->toHaveCount(0)
        ->and(linhasOcrSolOcr($this->tribunal))->toBe(['processamentos' => 0, 'abertos' => 0, 'assinantes' => 0]);

    Queue::assertNothingPushed();

    $trava = Cache::lock(SolicitarOcrProcessoService::chaveDaTrava($this->tribunal->id, NUMERO_SOL_OCR), 10);
    expect($trava->get())->toBeTrue();
    $trava->release();
})->with([
    'url nula' => ['services.n8n_ocr.url', null],
    'url vazia' => ['services.n8n_ocr.url', ''],
    'token nulo' => ['services.n8n_ocr.token', null],
    'token vazio' => ['services.n8n_ocr.token', '  '],
]);

it('300 processados → concluido com 0 jobs; com 2 novos no MNI → total 302 e só os 2 enfileirados', function () {
    [$processo, $documentos] = processoSolOcr($this->tribunal, array_fill(0, 300, ProcessoDocumento::OCR_PROCESSADO));

    $mni = mniSolOcr($documentos);

    $concluido = solicitarSolOcr(dadosSolOcr($this->tribunal));

    expect($concluido->paraCorpo())->toBe([
        'numero_processo' => NUMERO_SOL_OCR,
        'tribunal_id' => $this->tribunal->id,
        'total' => 300,
        'processados' => 300,
        'com_erro' => 0,
        'ignorados' => 0,
        'situacao' => 'concluido',
    ])
        ->and($concluido->processamentoId)->toBeNull()
        ->and($mni->requisicoes)->toHaveCount(1)
        ->and(linhasOcrSolOcr($this->tribunal))->toBe(['processamentos' => 0, 'abertos' => 0, 'assinantes' => 0]);
    conferirInvarianteSolOcr($concluido, $this->tribunal);

    Queue::assertNothingPushed();

    // 2 documentos novos no tribunal.
    mniSolOcr($documentos, [9400501 => 'application/pdf', 9400502 => 'image/png']);

    $reaberto = solicitarSolOcr(dadosSolOcr($this->tribunal));

    expect($reaberto->paraCorpo())->toMatchArray([
        'total' => 302,
        'processados' => 300,
        'com_erro' => 0,
        'ignorados' => 0,
        'situacao' => 'em_andamento',
    ]);
    conferirInvarianteSolOcr($reaberto, $this->tribunal);

    $novos = ProcessoDocumento::where('processo_id', $processo->id)
        ->whereIn('id_documento', [9400501, 9400502])->orderBy('id')->pluck('id')->all();

    Queue::assertPushed(BaixarDocumentoParaOcrJob::class, 2);
    Queue::assertNotPushed(EnviarDocumentoAoOcrJob::class);
    expect(idsDosJobsSolOcr(BaixarDocumentoParaOcrJob::class))->toBe($novos);
});

it('8 processados e 2 em erro → com_erro 0, em_andamento e só os 2 enfileirados; o job antigo vai para ocr_job_id_cancelar', function () {
    [$processo, $documentos] = processoSolOcr($this->tribunal, [
        ...array_fill(0, 8, ProcessoDocumento::OCR_PROCESSADO),
        // erro do envio anterior, com job: download de novo e DELETE antes do reenvio.
        ['ocr_situacao' => ProcessoDocumento::OCR_ERRO, 'ocr_job_id' => 'job-antigo-sol-1', 'ocr_erro' => 'falhou no workflow'],
        // erro sem job (ex.: 422 no envio), já baixado: direto ao envio, sem DELETE.
        [
            'ocr_situacao' => ProcessoDocumento::OCR_ERRO,
            'ocr_erro' => '422 no envio',
            'status' => ProcessoDocumento::STATUS_BAIXADO,
            'path' => 'documentos-processos/'.NUMERO_SOL_OCR.'/sol-baixado.pdf',
        ],
    ]);

    $mni = mniSolOcr($documentos);

    $resultado = solicitarSolOcr(dadosSolOcr($this->tribunal));

    expect($resultado->paraCorpo())->toMatchArray([
        'total' => 10,
        'processados' => 8,
        'com_erro' => 0,
        'ignorados' => 0,
        'situacao' => 'em_andamento',
    ])->and($mni->requisicoes)->toHaveCount(1);
    conferirInvarianteSolOcr($resultado, $this->tribunal);

    [$comJob, $semJob] = [$documentos[8]->fresh(), $documentos[9]->fresh()];

    expect($comJob->ocr_situacao)->toBe(ProcessoDocumento::OCR_PENDENTE)
        ->and($comJob->ocr_job_id)->toBeNull()
        ->and($comJob->ocr_job_id_cancelar)->toBe('job-antigo-sol-1')
        ->and($comJob->ocr_erro)->toBeNull()
        ->and($semJob->ocr_situacao)->toBe(ProcessoDocumento::OCR_PENDENTE)
        ->and($semJob->ocr_job_id_cancelar)->toBeNull();

    // 2 jobs: o download do que não está no S3 e o envio do já baixado.
    expect(idsDosJobsSolOcr(BaixarDocumentoParaOcrJob::class))->toBe([$comJob->id])
        ->and(idsDosJobsSolOcr(EnviarDocumentoAoOcrJob::class))->toBe([$semJob->id]);
    Queue::assertNotPushed(VerificarReenvioOcrJob::class);

    // Nenhum dos 8 processados volta.
    expect(ProcessoDocumento::where('processo_id', $processo->id)->where('ocr_situacao', ProcessoDocumento::OCR_PROCESSADO)->count())->toBe(8);

    // A queda de com_erro sai no 202 (D-08), sem aviso.
    expect(ProcessoOcrAviso::whereIn('assinante_id', ProcessoOcrAssinante::where('processamento_id', $resultado->processamentoId)->pluck('id'))->count())->toBe(0);
});

it('só vídeo e áudio → total 0, ignorados 2, concluido, sem processamento e sem job', function () {
    mniSolOcr([], [9400601 => 'video/mp4', 9400602 => 'audio/mpeg']);

    $resultado = solicitarSolOcr(dadosSolOcr($this->tribunal));

    expect($resultado->paraCorpo())->toBe([
        'numero_processo' => NUMERO_SOL_OCR,
        'tribunal_id' => $this->tribunal->id,
        'total' => 0,
        'processados' => 0,
        'com_erro' => 0,
        'ignorados' => 2,
        'situacao' => 'concluido',
    ])->and(linhasOcrSolOcr($this->tribunal))->toBe(['processamentos' => 0, 'abertos' => 0, 'assinantes' => 0]);
    conferirInvarianteSolOcr($resultado, $this->tribunal);

    Queue::assertNothingPushed();
});

it('só um enviado com idade < A → abre em_andamento, com 0 jobs, e o documento segue aguardando retorno (RF-18 item 4)', function () {
    $minutosA = (int) config('services.n8n_ocr.idade_maxima_sem_retorno_minutos');

    [$processo, $documentos] = processoSolOcr($this->tribunal, [
        ProcessoDocumento::OCR_PROCESSADO,
        ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_job_id' => 'job-recente-sol', 'ocr_enviado_em' => now()->subMinutes($minutosA - 1)],
    ]);

    mniSolOcr($documentos);

    $resultado = solicitarSolOcr(dadosSolOcr($this->tribunal));

    expect($resultado->paraCorpo())->toMatchArray([
        'total' => 2,
        'processados' => 1,
        'com_erro' => 0,
        'situacao' => 'em_andamento',
    ])
        ->and($resultado->carona)->toBeFalse()
        ->and(linhasOcrSolOcr($this->tribunal))->toBe(['processamentos' => 1, 'abertos' => 1, 'assinantes' => 1]);

    Queue::assertNothingPushed();

    $enviado = $documentos[1]->fresh();
    expect($enviado->ocr_situacao)->toBe(ProcessoDocumento::OCR_ENVIADO)
        ->and($enviado->ocr_job_id)->toBe('job-recente-sol');
});

it('enviado com idade A + 1 → 1 VerificarReenvioOcrJob, 0 envios, o mesmo ocr_job_id e ocr_job_id_cancelar nulo', function () {
    $minutosA = (int) config('services.n8n_ocr.idade_maxima_sem_retorno_minutos');

    [$processo, $documentos] = processoSolOcr($this->tribunal, [
        ProcessoDocumento::OCR_PROCESSADO,
        [
            'ocr_situacao' => ProcessoDocumento::OCR_ENVIADO,
            'ocr_job_id' => 'job-velho-sol',
            'ocr_enviado_em' => now()->subMinutes($minutosA + 1),
            'status' => ProcessoDocumento::STATUS_BAIXADO,
            'path' => 'documentos-processos/'.NUMERO_SOL_OCR.'/sol-velho.pdf',
        ],
    ]);

    mniSolOcr($documentos);

    $resultado = solicitarSolOcr(dadosSolOcr($this->tribunal));

    expect($resultado->situacao)->toBe('em_andamento')
        ->and($resultado->comErro)->toBe(0);

    Queue::assertPushed(VerificarReenvioOcrJob::class, 1);
    Queue::assertPushed(VerificarReenvioOcrJob::class, fn (VerificarReenvioOcrJob $job) => $job->processamentoId === $resultado->processamentoId
        && $job->documentoId === $documentos[1]->id);
    Queue::assertPushedOn(EnviarDocumentoAoOcrJob::FILA, VerificarReenvioOcrJob::class);
    Queue::assertNotPushed(EnviarDocumentoAoOcrJob::class);
    Queue::assertNotPushed(BaixarDocumentoParaOcrJob::class);

    $enviado = $documentos[1]->fresh();
    expect($enviado->ocr_situacao)->toBe(ProcessoDocumento::OCR_ENVIADO)
        ->and($enviado->ocr_job_id)->toBe('job-velho-sol')
        ->and($enviado->ocr_job_id_cancelar)->toBeNull();

    // A consulta não leva o par.
    expect(payloadsDosJobsSolOcr())->not->toContain(LOGIN_DONO_SOL_OCR)
        ->and(payloadsDosJobsSolOcr())->not->toContain(SENHA_DONO_SOL_OCR);
});

it('pendente já baixado com path → envio direto, sem job de download', function () {
    [$processo, $documentos] = processoSolOcr($this->tribunal, [[
        'status' => ProcessoDocumento::STATUS_BAIXADO,
        'path' => 'documentos-processos/'.NUMERO_SOL_OCR.'/sol-ja-baixado.pdf',
    ]]);

    mniSolOcr($documentos);

    $resultado = solicitarSolOcr(dadosSolOcr($this->tribunal));

    expect($resultado->situacao)->toBe('em_andamento')
        ->and(idsDosJobsSolOcr(EnviarDocumentoAoOcrJob::class))->toBe([$documentos[0]->id]);

    Queue::assertPushedOn(EnviarDocumentoAoOcrJob::FILA, EnviarDocumentoAoOcrJob::class);
    Queue::assertPushed(EnviarDocumentoAoOcrJob::class, fn (EnviarDocumentoAoOcrJob $job) => $job->processamentoId === $resultado->processamentoId);
    Queue::assertNotPushed(BaixarDocumentoParaOcrJob::class);
    expect(payloadsDosJobsSolOcr())->not->toContain(SENHA_DONO_SOL_OCR);
});

it('documento baixado sem path vai ao download', function () {
    [, $documentos] = processoSolOcr($this->tribunal, [[
        'status' => ProcessoDocumento::STATUS_BAIXADO,
        'path' => null,
    ]]);

    mniSolOcr($documentos);

    solicitarSolOcr(dadosSolOcr($this->tribunal));

    expect(idsDosJobsSolOcr(BaixarDocumentoParaOcrJob::class))->toBe([$documentos[0]->id]);
    Queue::assertNotPushed(EnviarDocumentoAoOcrJob::class);
});

it('depois de um processamento falho pela recusa, outro par abre outro processamento e enfileira só os pendentes (RF-18 AC a)', function () {
    [$processo, $documentos] = processoSolOcr($this->tribunal, [
        ...array_fill(0, 3, ['ocr_situacao' => ProcessoDocumento::OCR_ENVIADO, 'ocr_enviado_em' => now()->subMinutes(3)]),
        ...array_fill(0, 7, ProcessoDocumento::OCR_PENDENTE),
    ]);
    foreach ($documentos->take(3) as $i => $documento) {
        $documento->update(['ocr_job_id' => "job-proc1-sol-{$i}"]);
    }

    $processamento1 = ProcessoOcrProcessamento::create([
        'processo_id' => $processo->id,
        'situacao' => ProcessoOcrProcessamento::SITUACAO_FALHOU,
        'codigo_falha' => AvisarAssinantesOcrService::CODIGO_RECUSA,
        'finalizado_em' => now(),
    ]);

    mniSolOcr($documentos);

    $resultado = solicitarSolOcr(dadosSolOcr($this->tribunal, 'carona'));

    expect($resultado->carona)->toBeFalse()
        ->and($resultado->processamentoId)->not->toBe($processamento1->id)
        ->and($resultado->paraCorpo())->toMatchArray(['total' => 10, 'processados' => 0, 'com_erro' => 0, 'situacao' => 'em_andamento'])
        ->and(linhasOcrSolOcr($this->tribunal))->toMatchArray(['processamentos' => 2, 'abertos' => 1]);

    Queue::assertPushed(BaixarDocumentoParaOcrJob::class, 7);
    Queue::assertNotPushed(VerificarReenvioOcrJob::class);
    expect(idsDosJobsSolOcr(BaixarDocumentoParaOcrJob::class))->toBe($documentos->slice(3)->pluck('id')->sort()->values()->all());

    // Os downloads do 2º processamento levam o par de quem o abriu.
    Queue::assertPushed(BaixarDocumentoParaOcrJob::class, fn (BaixarDocumentoParaOcrJob $job) => parDoJobDownloadSolOcr($job) === [LOGIN_CARONA_SOL_OCR, SENHA_CARONA_SOL_OCR]);
});

it('a corrida perdida na abertura vira carona do processamento aberto (índice único, D2)', function () {
    [$processo, $documentos] = processoSolOcr($this->tribunal, [null, null]);

    // Outro pedido abriu, mas este não o viu nas 2 leituras antes do insert,
    // como se a trava do cache tivesse vencido durante a consulta ao MNI.
    $doOutroPedido = ProcessoOcrProcessamento::create(['processo_id' => $processo->id]);
    ProcessoOcrAssinante::create([
        'processamento_id' => $doOutroPedido->id,
        'dono' => true,
        'callback_url' => 'https://sistema.example/mni/processamentos/outro/progresso',
        'callback_token' => 'tok-outro-pedido',
    ]);

    $cego = new class(app(ContagemOcrService::class), app(AvisarAssinantesOcrService::class)) extends ProcessamentoOcrService
    {
        public int $leiturasEscondidas = 2;

        public function processamentoAberto(int $processoId, bool $travar = false): ?ProcessoOcrProcessamento
        {
            if ($this->leiturasEscondidas > 0) {
                $this->leiturasEscondidas--;

                return null;
            }

            return parent::processamentoAberto($processoId, $travar);
        }
    };
    app()->instance(ProcessamentoOcrService::class, $cego);

    $mni = mniSolOcr($documentos);

    $resultado = solicitarSolOcr(dadosSolOcr($this->tribunal));

    expect($cego->leiturasEscondidas)->toBe(0)
        ->and($resultado->carona)->toBeTrue()
        ->and($resultado->processamentoId)->toBe($doOutroPedido->id)
        ->and($resultado->paraCorpo())->toMatchArray(['total' => 2, 'processados' => 0, 'situacao' => 'em_andamento'])
        ->and($mni->requisicoes)->toHaveCount(1)
        ->and(linhasOcrSolOcr($this->tribunal))->toBe(['processamentos' => 1, 'abertos' => 1, 'assinantes' => 2])
        ->and(ProcessoOcrAssinante::where('processamento_id', $doOutroPedido->id)->where('dono', false)->count())->toBe(1);

    // A transação segue válida depois do insert desfeito no savepoint.
    expect(ProcessoDocumento::where('processo_id', $processo->id)->count())->toBe(2);

    Queue::assertNothingPushed();
});

it('não registra o par nem o callback_token nos logs da abertura, da carona e da falha', function () {
    $mensagens = [];
    Event::listen(MessageLogged::class, function (MessageLogged $evento) use (&$mensagens) {
        $mensagens[] = $evento->message.' '.json_encode($evento->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    });

    mniSolOcr([], [9400701 => 'application/pdf', 9400702 => 'application/pdf']);

    solicitarSolOcr(dadosSolOcr($this->tribunal));
    solicitarSolOcr(dadosSolOcr($this->tribunal, 'carona'));

    $outro = Tribunal::factory()->create(['ativo' => true, 'url_webservice_mni_consultar_processo' => URL_LINHA_SOL_OCR]);
    simularIntegracaoMni(fn () => (object) ['sucesso' => false, 'mensagem' => RECUSA_SOL_OCR]);

    try {
        solicitarSolOcr(dadosSolOcr($outro));
    } catch (CredencialPjeRecusadaException) {
    }

    $texto = implode("\n", $mensagens);

    expect($mensagens)->not->toBeEmpty();

    foreach ([LOGIN_DONO_SOL_OCR, SENHA_DONO_SOL_OCR, LOGIN_CARONA_SOL_OCR, SENHA_CARONA_SOL_OCR, TOKEN_CALLBACK_DONO_SOL_OCR, TOKEN_CALLBACK_CARONA_SOL_OCR] as $segredo) {
        expect($texto)->not->toContain($segredo);
    }
});

it('o DTO do pedido marca o par e o callback_token como sensíveis e os mascara no dump', function () {
    $dados = dadosSolOcr($this->tribunal);

    $parametros = collect((new ReflectionMethod(DadosSolicitacaoOcr::class, '__construct'))->getParameters())
        ->keyBy(fn (ReflectionParameter $p) => $p->getName());

    foreach (['login', 'senha', 'callbackToken'] as $nome) {
        expect($parametros[$nome]->getAttributes(SensitiveParameter::class))->toHaveCount(1);
    }

    foreach (['numeroProcesso', 'tribunalId', 'callbackUrl', 'apiTokenId'] as $nome) {
        expect($parametros[$nome]->getAttributes(SensitiveParameter::class))->toHaveCount(0);
    }

    $despejo = print_r($dados, true);

    expect($despejo)->toContain(NUMERO_SOL_OCR)
        ->and($despejo)->not->toContain(LOGIN_DONO_SOL_OCR)
        ->and($despejo)->not->toContain(SENHA_DONO_SOL_OCR)
        ->and($despejo)->not->toContain(TOKEN_CALLBACK_DONO_SOL_OCR);
});

// ---------- T08: o mimetype vazio no pedido (RF-08, RF-13 atalho do pedido) ----------

/**
 * Os contextos dos logs `jobs do pedido despachados`, em `->contextos`.
 */
function capturarLogsDeDespachoSolOcr(): stdClass
{
    $capturados = new stdClass();
    $capturados->contextos = [];

    Event::listen(MessageLogged::class, function (MessageLogged $evento) use ($capturados) {
        if (str_contains($evento->message, 'jobs do pedido despachados')) {
            $capturados->contextos[] = $evento->context;
        }
    });

    return $capturados;
}

/**
 * Só a consulta da lista vai ao tribunal no pedido: nenhum download SOAP.
 */
function operacoesSoapSolOcr(stdClass $mni): array
{
    return array_column($mni->requisicoes, 'operacao');
}

it('1 PDF processado e 1 vazio pendente → em_andamento, total 2, processados 1, ignorados 0, 1 download e 0 envio', function () {
    $logs = capturarLogsDeDespachoSolOcr();
    [, $documentos] = processoSolOcr($this->tribunal, [
        ProcessoDocumento::OCR_PROCESSADO,
        ['mimetype' => '', 'ocr_situacao' => null, 'status' => ProcessoDocumento::STATUS_PENDENTE],
    ]);
    $mni = mniSolOcr($documentos);

    $dados = dadosSolOcr($this->tribunal);
    $resultado = solicitarSolOcr($dados);

    expect($resultado->paraCorpo())->toBe([
        'numero_processo' => NUMERO_SOL_OCR,
        'tribunal_id' => $this->tribunal->id,
        'total' => 2,
        'processados' => 1,
        'com_erro' => 0,
        'ignorados' => 0,
        'situacao' => 'em_andamento',
    ]);
    conferirInvarianteSolOcr($resultado, $this->tribunal);

    expect(idsDosJobsSolOcr(BaixarDocumentoParaOcrJob::class))->toBe([$documentos[1]->id]);
    Queue::assertPushedOn(BaixarDocumentoParaOcrJob::FILA, BaixarDocumentoParaOcrJob::class);
    Queue::assertNotPushed(EnviarDocumentoAoOcrJob::class);

    // cifrado e com o par do dono
    Queue::assertPushed(BaixarDocumentoParaOcrJob::class, fn (BaixarDocumentoParaOcrJob $job) => $job->processamentoId === $resultado->processamentoId
        && $job instanceof \Illuminate\Contracts\Queue\ShouldBeEncrypted
        && parDoJobDownloadSolOcr($job) === [LOGIN_DONO_SOL_OCR, SENHA_DONO_SOL_OCR]);

    expect(operacoesSoapSolOcr($mni))->toBe(['consultarProcesso'])
        ->and($documentos[1]->fresh()->mimetype)->toBe('');

    // o log do despacho conta o vazio, sem o par
    expect($logs->contextos)->toHaveCount(1)
        ->and($logs->contextos[0])->toMatchArray([
            'downloads' => 1,
            'downloads_sem_mimetype' => 1,
            'envios' => 0,
        ])
        ->and(json_encode($logs->contextos[0]))->not->toContain(LOGIN_DONO_SOL_OCR)
        ->and(json_encode($logs->contextos[0]))->not->toContain(SENHA_DONO_SOL_OCR);
});

it('vazio pendente já baixado com path .dat no S3 → 1 download e 0 envio', function () {
    Storage::fake('s3');
    $path = 'documentos-processos/'.NUMERO_SOL_OCR.'/sol-vazio.dat';
    Storage::disk('s3')->put($path, 'binario-sem-mimetype');

    [, $documentos] = processoSolOcr($this->tribunal, [[
        'mimetype' => '',
        'status' => ProcessoDocumento::STATUS_BAIXADO,
        'path' => $path,
    ]]);
    $mni = mniSolOcr($documentos);

    $resultado = solicitarSolOcr(dadosSolOcr($this->tribunal));

    expect($resultado->situacao)->toBe('em_andamento')
        ->and($resultado->total)->toBe(1)
        ->and($resultado->ignorados)->toBe(0)
        ->and(idsDosJobsSolOcr(BaixarDocumentoParaOcrJob::class))->toBe([$documentos[0]->id])
        ->and(operacoesSoapSolOcr($mni))->toBe(['consultarProcesso']);

    Queue::assertPushedOn(BaixarDocumentoParaOcrJob::FILA, BaixarDocumentoParaOcrJob::class);
    Queue::assertNotPushed(EnviarDocumentoAoOcrJob::class);
});

it('vazio com erro volta a pendente, gera 1 download e o pedido sai com com_erro 0', function () {
    [, $documentos] = processoSolOcr($this->tribunal, [
        ProcessoDocumento::OCR_PROCESSADO,
        [
            'mimetype' => '',
            'ocr_situacao' => ProcessoDocumento::OCR_ERRO,
            'ocr_erro' => 'mimetype não informado pelo tribunal',
            'ocr_job_id' => 'job-antigo-vazio-sol',
        ],
    ]);
    mniSolOcr($documentos);

    $resultado = solicitarSolOcr(dadosSolOcr($this->tribunal));

    expect($resultado->paraCorpo())->toMatchArray([
        'total' => 2,
        'processados' => 1,
        'com_erro' => 0,
        'ignorados' => 0,
        'situacao' => 'em_andamento',
    ]);
    conferirInvarianteSolOcr($resultado, $this->tribunal);

    $vazio = $documentos[1]->fresh();

    expect($vazio->ocr_situacao)->toBe(ProcessoDocumento::OCR_PENDENTE)
        ->and($vazio->ocr_erro)->toBeNull()
        ->and($vazio->ocr_job_id)->toBeNull()
        ->and($vazio->ocr_job_id_cancelar)->toBe('job-antigo-vazio-sol')
        ->and($vazio->mimetype)->toBe('')
        ->and(idsDosJobsSolOcr(BaixarDocumentoParaOcrJob::class))->toBe([$vazio->id]);

    Queue::assertNotPushed(EnviarDocumentoAoOcrJob::class);
});

it('só vazios pendentes → abre o processamento em_andamento, e não concluido', function () {
    [, $documentos] = processoSolOcr($this->tribunal, [
        ['mimetype' => ''],
        ['mimetype' => '', 'status' => ProcessoDocumento::STATUS_BAIXADO, 'path' => 'documentos-processos/'.NUMERO_SOL_OCR.'/so-vazio.dat'],
    ]);
    mniSolOcr($documentos);

    $resultado = solicitarSolOcr(dadosSolOcr($this->tribunal));

    expect($resultado->paraCorpo())->toMatchArray([
        'total' => 2,
        'processados' => 0,
        'com_erro' => 0,
        'ignorados' => 0,
        'situacao' => 'em_andamento',
    ])
        ->and($resultado->processamentoId)->not->toBeNull()
        ->and(linhasOcrSolOcr($this->tribunal))->toBe(['processamentos' => 1, 'abertos' => 1, 'assinantes' => 1])
        ->and(idsDosJobsSolOcr(BaixarDocumentoParaOcrJob::class))->toBe($documentos->pluck('id')->sort()->values()->all());

    Queue::assertNotPushed(EnviarDocumentoAoOcrJob::class);
});

it('PDF baixado com path continua indo direto a ocr-envio, com 0 SOAP além da lista', function () {
    [, $documentos] = processoSolOcr($this->tribunal, [[
        'mimetype' => 'application/pdf',
        'status' => ProcessoDocumento::STATUS_BAIXADO,
        'path' => 'documentos-processos/'.NUMERO_SOL_OCR.'/sol-pdf-baixado.pdf',
    ]]);
    $mni = mniSolOcr($documentos);

    $resultado = solicitarSolOcr(dadosSolOcr($this->tribunal));

    expect($resultado->situacao)->toBe('em_andamento')
        ->and(idsDosJobsSolOcr(EnviarDocumentoAoOcrJob::class))->toBe([$documentos[0]->id])
        ->and(operacoesSoapSolOcr($mni))->toBe(['consultarProcesso']);

    Queue::assertPushedOn(EnviarDocumentoAoOcrJob::FILA, EnviarDocumentoAoOcrJob::class);
    Queue::assertNotPushed(BaixarDocumentoParaOcrJob::class);
});

it('mídia baixada com path nunca vai ao envio nem ao download pelo pedido (RF-13)', function () {
    [, $documentos] = processoSolOcr($this->tribunal, [
        ['mimetype' => 'audio/mpeg', 'status' => ProcessoDocumento::STATUS_BAIXADO, 'path' => 'documentos-processos/'.NUMERO_SOL_OCR.'/sol-audio.mp3'],
        ['mimetype' => 'application/pdf'],
    ]);
    mniSolOcr($documentos);

    $resultado = solicitarSolOcr(dadosSolOcr($this->tribunal));

    expect($resultado->paraCorpo())->toMatchArray(['total' => 1, 'ignorados' => 1, 'situacao' => 'em_andamento'])
        ->and(idsDosJobsSolOcr(BaixarDocumentoParaOcrJob::class))->toBe([$documentos[1]->id]);

    Queue::assertNotPushed(EnviarDocumentoAoOcrJob::class);
});
