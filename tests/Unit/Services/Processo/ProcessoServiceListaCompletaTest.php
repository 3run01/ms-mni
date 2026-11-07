<?php

use App\Exceptions\CredencialPjeRecusadaException;
use App\Exceptions\MNIException;
use App\Exceptions\ProcessoNaoEncontradoNoTribunalException;
use App\Jobs\BaixarDocumentoMNIJob;
use App\Jobs\BaixarDocumentosMNIEmLoteJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\ProcessoMovimento;
use App\Models\ProcessoParte;
use App\Models\Tribunal;
use App\Services\Processo\ProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;

/*
 * Lista completa do processo para o OCR (T12, RF-04 item 1, D8): sem o
 * recorte de 90 dias, só na linha informada, com o par do pedido e sem
 * enfileirar download de binário.
 */

uses(DatabaseTransactions::class);

const NUMERO_LISTA_COMPLETA_OCR = '12345674220268260100';
const LOGIN_LISTA_COMPLETA_OCR = 'login-lista-5a3';
const SENHA_LISTA_COMPLETA_OCR = 'senha-lista-8e1';
const URL_LINHA_LISTA_COMPLETA_OCR = 'https://tjap-1grau.example/mni/consultar-processo';
const URL_OUTRA_LINHA_LISTA_COMPLETA_OCR = 'https://tjap-2grau.example/mni/consultar-processo';
const RECUSA_LISTA_COMPLETA_OCR = 'Erro ao realizar login via MNI. exception invoking: loginFailed';

beforeEach(function () {
    Queue::fake();
    definirCredenciaisPadrao('login-padrao-lista', 'senha-padrao-lista');

    // Duas linhas ativas da mesma sigla (DEP-02): só a informada é consultada.
    $this->tribunal = Tribunal::factory()->create([
        'sigla' => 'TJAP',
        'grau' => '1',
        'login' => 'login-do-tribunal',
        'password' => Crypt::encrypt('{SENHA}'),
        'url_webservice_mni_consultar_processo' => URL_LINHA_LISTA_COMPLETA_OCR,
        'ativo' => true,
    ]);
    $this->outraLinha = Tribunal::factory()->create([
        'sigla' => 'TJAP',
        'grau' => '2',
        'login' => 'login-do-tribunal',
        'password' => Crypt::encrypt('{SENHA}'),
        'url_webservice_mni_consultar_processo' => URL_OUTRA_LINHA_LISTA_COMPLETA_OCR,
        'ativo' => true,
    ]);
});

/**
 * Retorno de consultarProcesso com sucesso, cru como o MNI devolve.
 *
 * @param  list<object>  $documentos
 */
function retornoListaCompletaOcr(array $documentos): object
{
    return (object) [
        'sucesso' => true,
        'processo' => (object) [
            'dadosBasicos' => (object) [
                'numero' => NUMERO_LISTA_COMPLETA_OCR,
                'codigoLocalidade' => '1',
                'classeProcessual' => '7',
                'nivelSigilo' => 0,
                'valorCausa' => 1500,
                'polo' => [
                    (object) [
                        'polo' => 'AT',
                        'parte' => (object) [
                            'pessoa' => (object) [
                                'nome' => 'Parte Autora da Lista',
                                'numeroDocumentoPrincipal' => '12345678900',
                            ],
                        ],
                    ],
                ],
            ],
            'movimento' => [
                (object) [
                    'identificadorMovimento' => 'MOV-LISTA-1',
                    'dataHora' => '20260105100000',
                    'movimentoNacional' => (object) ['codigoNacional' => '123'],
                ],
            ],
            'documento' => $documentos,
        ],
    ];
}

function consultarListaCompletaOcr(Tribunal $tribunal): Processo
{
    return app(ProcessoService::class)->consultarListaCompletaParaOcr(
        $tribunal,
        NUMERO_LISTA_COMPLETA_OCR,
        LOGIN_LISTA_COMPLETA_OCR,
        SENHA_LISTA_COMPLETA_OCR,
    );
}

it('consulta só a linha informada, com o par do pedido, dataReferencia nulo e documentos incluídos', function () {
    $mni = simularIntegracaoMni(fn () => retornoListaCompletaOcr([documentoMniFake(9300001)]));

    consultarListaCompletaOcr($this->tribunal);

    expect($mni->requisicoes)->toHaveCount(1)
        ->and($mni->urls)->toBe([URL_LINHA_LISTA_COMPLETA_OCR])
        ->and($mni->urls)->not->toContain(URL_OUTRA_LINHA_LISTA_COMPLETA_OCR);

    $requisicao = $mni->requisicoes[0];

    expect($requisicao['operacao'])->toBe('consultarProcesso')
        ->and($requisicao['parametros'])->toMatchArray([
            'idConsultante' => LOGIN_LISTA_COMPLETA_OCR,
            'senhaConsultante' => SENHA_LISTA_COMPLETA_OCR,
            'numeroProcesso' => NUMERO_LISTA_COMPLETA_OCR,
            'incluirCabecalho' => true,
            'movimentos' => true,
            'incluirDocumentos' => true,
        ])
        ->and($requisicao['parametros'])->toHaveKey('dataReferencia')
        ->and($requisicao['parametros']['dataReferencia'])->toBeNull();
});

it('grava o processo na linha informada, e não na outra linha da mesma sigla', function () {
    simularIntegracaoMni(fn () => retornoListaCompletaOcr([documentoMniFake(9300002)]));

    $processo = consultarListaCompletaOcr($this->tribunal);

    expect($processo)->toBeInstanceOf(Processo::class)
        ->and($processo->exists)->toBeTrue()
        ->and($processo->tribunal_id)->toBe($this->tribunal->id)
        ->and($processo->numero_processo)->toBe(NUMERO_LISTA_COMPLETA_OCR)
        ->and(Processo::where('tribunal_id', $this->outraLinha->id)->count())->toBe(0)
        ->and(ProcessoDocumento::where('processo_id', $processo->id)->count())->toBe(1);
});

it('grava o documento juntado há 120 dias, que o recorte de 90 dias deixaria de fora', function () {
    $dataAntiga = now()->subDays(120)->startOfSecond();

    simularIntegracaoMni(fn () => retornoListaCompletaOcr([
        documentoMniFake(9300003, ['dataHora' => $dataAntiga->format('YmdHis')]),
        documentoMniFake(9300004),
    ]));

    $processo = consultarListaCompletaOcr($this->tribunal);

    $antigo = ProcessoDocumento::where('processo_id', $processo->id)->where('id_documento', 9300003)->first();

    expect($antigo)->not->toBeNull()
        ->and($antigo->data_hora)->toBe($dataAntiga->format('Y-m-d H:i:s'));
});

it('grava dados básicos, partes, movimentos, documentos e vinculados como consultarNumero', function () {
    simularIntegracaoMni(fn () => retornoListaCompletaOcr([
        documentoMniFake(9300005, ['documentoVinculado' => documentoMniFake(9300006)]),
        documentoMniFake(9300007, ['mimetype' => 'video/mp4']),
        documentoMniFake(9300008, ['mimetype' => 'text/html']),
    ]));

    $processo = consultarListaCompletaOcr($this->tribunal);

    expect((float) $processo->valor_causa)->toBe(1500.0)
        ->and(ProcessoParte::where('processo_id', $processo->id)->pluck('nome')->all())->toBe(['Parte Autora da Lista'])
        ->and(ProcessoMovimento::where('processo_id', $processo->id)->pluck('identificador_movimento')->all())->toBe(['MOV-LISTA-1'])
        ->and(ProcessoDocumento::where('processo_id', $processo->id)->orderBy('id_documento')->pluck('id_documento')->all())
        ->toBe([9300005, 9300006, 9300007, 9300008]);
});

it('não enfileira download de binário: 0 jobs em lote e 0 jobs unitários', function () {
    simularIntegracaoMni(fn () => retornoListaCompletaOcr([
        documentoMniFake(9300009),
        documentoMniFake(9300010, ['mimetype' => 'image/png']),
        documentoMniFake(9300011, ['mimetype' => 'video/mp4']),
        documentoMniFake(9300012, ['mimetype' => 'video/quicktime']),
    ]));

    $processo = consultarListaCompletaOcr($this->tribunal);

    Queue::assertPushed(BaixarDocumentosMNIEmLoteJob::class, 0);
    Queue::assertPushed(BaixarDocumentoMNIJob::class, 0);
    Queue::assertNothingPushed();

    expect(ProcessoDocumento::where('processo_id', $processo->id)->pluck('status')->unique()->all())
        ->toBe([ProcessoDocumento::STATUS_PENDENTE]);
});

it('documento já gravado mantém o binário e a situação do OCR', function () {
    [$processo, $documentos] = criarDocumentosLote([[
        'id_documento' => 9300013,
        'status' => ProcessoDocumento::STATUS_BAIXADO,
        'path' => 'documentos-processos/'.NUMERO_LISTA_COMPLETA_OCR.'/9300013.pdf',
        'ocr_situacao' => ProcessoDocumento::OCR_PROCESSADO,
    ]], ['tribunal_id' => $this->tribunal->id, 'numero_processo' => NUMERO_LISTA_COMPLETA_OCR]);

    simularIntegracaoMni(fn () => retornoListaCompletaOcr([
        documentoMniFake(9300013, ['descricao' => 'Descrição nova']),
        documentoMniFake(9300014),
    ]));

    expect(consultarListaCompletaOcr($this->tribunal)->id)->toBe($processo->id);

    $existente = $documentos->first()->fresh();

    expect($existente->status)->toBe(ProcessoDocumento::STATUS_BAIXADO)
        ->and($existente->ocr_situacao)->toBe(ProcessoDocumento::OCR_PROCESSADO)
        ->and($existente->descricao)->toBe('Descrição nova')
        ->and(ProcessoDocumento::where('processo_id', $processo->id)->count())->toBe(2);
});

it('a recusa do par sobe como CredencialPjeRecusadaException, sem embrulho e sem gravar nada', function (Closure $responder) {
    $mni = simularIntegracaoMni($responder);

    $excecao = null;

    try {
        consultarListaCompletaOcr($this->tribunal);
    } catch (Throwable $e) {
        $excecao = $e;
    }

    expect($excecao)->not->toBeNull()
        ->and(get_class($excecao))->toBe(CredencialPjeRecusadaException::class)
        ->and($mni->requisicoes)->toHaveCount(1)
        ->and(Processo::where('numero_processo', NUMERO_LISTA_COMPLETA_OCR)->count())->toBe(0);

    Queue::assertNothingPushed();
})->with([
    'sucesso = false' => [fn () => (object) ['sucesso' => false, 'mensagem' => RECUSA_LISTA_COMPLETA_OCR]],
    'exceção de recibo' => [fn () => throw new Exception(RECUSA_LISTA_COMPLETA_OCR, 0)],
    'SoapFault' => [fn () => throw new SoapFault('Server', RECUSA_LISTA_COMPLETA_OCR)],
]);

it('o processo inexistente sobe como ProcessoNaoEncontradoNoTribunalException, sem embrulho e sem gravar nada', function () {
    $mni = simularIntegracaoMni(fn () => (object) [
        'sucesso' => false,
        'mensagem' => 'Processo de número '.NUMERO_LISTA_COMPLETA_OCR.' não encontrado!',
    ]);

    $excecao = null;

    try {
        consultarListaCompletaOcr($this->tribunal);
    } catch (Throwable $e) {
        $excecao = $e;
    }

    expect($excecao)->not->toBeNull()
        ->and(get_class($excecao))->toBe(ProcessoNaoEncontradoNoTribunalException::class)
        ->and($mni->requisicoes)->toHaveCount(1)
        ->and(Processo::where('numero_processo', NUMERO_LISTA_COMPLETA_OCR)->count())->toBe(0);
});

it('controle: outra falha do MNI segue MNIException sem código', function () {
    simularIntegracaoMni(fn () => (object) ['sucesso' => false, 'mensagem' => 'Serviço temporariamente indisponível']);

    $excecao = null;

    try {
        consultarListaCompletaOcr($this->tribunal);
    } catch (Throwable $e) {
        $excecao = $e;
    }

    expect(get_class($excecao))->toBe(MNIException::class);
});

it('controle: consultarNumero continua com o recorte de 90 dias e despacha o download', function () {
    $mni = simularIntegracaoMni(fn () => retornoListaCompletaOcr([documentoMniFake(9300015)]));

    app(ProcessoService::class)->consultarNumero(
        $this->tribunal,
        NUMERO_LISTA_COMPLETA_OCR,
        LOGIN_LISTA_COMPLETA_OCR,
        SENHA_LISTA_COMPLETA_OCR,
    );

    expect($mni->requisicoes[0]['parametros']['dataReferencia'])
        ->toBe(date('Ymd', strtotime('-90 days')).'000001');

    Queue::assertPushed(BaixarDocumentosMNIEmLoteJob::class, 1);
});

it('marca o login e a senha como #[\SensitiveParameter]', function () {
    $metodo = new ReflectionMethod(ProcessoService::class, 'consultarListaCompletaParaOcr');
    $parametros = collect($metodo->getParameters())->keyBy(fn (ReflectionParameter $p) => $p->getName());

    foreach (['login', 'senha'] as $nome) {
        expect($parametros[$nome]->getAttributes(SensitiveParameter::class))->toHaveCount(1);
    }

    foreach (['tribunal', 'numero'] as $nome) {
        expect($parametros[$nome]->getAttributes(SensitiveParameter::class))->toHaveCount(0);
    }
});
