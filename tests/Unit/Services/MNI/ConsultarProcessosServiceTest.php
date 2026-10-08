<?php

use App\Exceptions\ConsultaMNISemSucessoException;
use App\Exceptions\MNIException;
use App\Models\Tribunal;
use App\Services\IntegracaoBase;
use App\Services\MNI\Intercomunicacao\ConsultarProcessosService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

const URL_MNI_CONSULTAR_PROCESSOS = 'https://mni.tribunal.exemplo/intercomunicacao?wsdl';
const URL_CONSULTAR_PROCESSO_CONSULTAR_PROCESSOS = 'https://consulta.tribunal.exemplo/intercomunicacao?wsdl';
const NUMERO_PROCESSO_CONSULTAR_PROCESSOS = '0000001-02.2024.8.26.0001';

function tribunalConsultarProcessos(array $atributos = []): Tribunal
{
    return Tribunal::factory()->make(array_merge([
        'login' => 'login-do-tribunal',
        'password' => Crypt::encrypt('{SENHA}'),
        'url_webservice_mni' => URL_MNI_CONSULTAR_PROCESSOS,
        'url_webservice_mni_consultar_processo' => URL_CONSULTAR_PROCESSO_CONSULTAR_PROCESSOS,
    ], $atributos));
}

function documentoMniConsultarProcessos(int|string $id, array $extras = []): object
{
    return (object) array_merge([
        'idDocumento' => $id,
        'mimetype' => 'application/pdf',
        'conteudo' => '%PDF-cru-' . $id,
    ], $extras);
}

function respostaConsultarProcessos(mixed $processos): object
{
    return (object) [
        'sucesso' => true,
        'mensagem' => 'Processos consultados com sucesso',
        'processos' => $processos,
        'pagina' => 1,
        'tamanhoPagina' => 20,
        'totalRegistros' => 1,
    ];
}

/**
 * Troca o IntegracaoBase do container por um mock, guardando a URL usada na
 * criação e cada makeSoapRequest/possuiOperacao.
 */
function simularIntegracaoConsultarProcessos(object $chamadas, ?Closure $responder = null, ?Closure $possuiOperacao = null): void
{
    $chamadas->urls = [];
    $chamadas->requisicoes = [];
    $chamadas->operacoesVerificadas = [];

    app()->bind(IntegracaoBase::class, function ($app, $params) use ($chamadas, $responder, $possuiOperacao) {
        $chamadas->urls[] = $params['url'] ?? null;

        $integracao = Mockery::mock(IntegracaoBase::class);
        $integracao->shouldReceive('makeSoapRequest')
            ->andReturnUsing(function (string $operacao, array $parametros) use ($chamadas, $responder) {
                $chamadas->requisicoes[] = ['operacao' => $operacao, 'parametros' => $parametros];

                return $responder($operacao, $parametros);
            });
        $integracao->shouldReceive('possuiOperacao')
            ->andReturnUsing(function (string $operacao) use ($chamadas, $possuiOperacao) {
                $chamadas->operacoesVerificadas[] = $operacao;

                return $possuiOperacao($operacao);
            });

        return $integracao;
    });
}

function consultarPaginaPadrao(array $ids = ['5001'], ?string $login = null, ?string $senha = null, array $tribunal = []): array
{
    return (new ConsultarProcessosService())->consultarPagina(
        tribunalConsultarProcessos($tribunal),
        NUMERO_PROCESSO_CONSULTAR_PROCESSOS,
        $ids,
        1,
        20,
        $login,
        $senha,
    );
}

function chavesConsultarProcessos(array $mapa): array
{
    return array_map('strval', array_keys($mapa));
}

beforeEach(function () {
    $this->chamadas = new stdClass();
});

// consultarPagina: requisição --------------------------------------------------

it('envia os 9 campos do CT-01 com 20 ids, pagina 2 e tamanhoPagina 20', function () {
    simularIntegracaoConsultarProcessos($this->chamadas, fn () => respostaConsultarProcessos((object) []));
    $ids = range(6001, 6020);

    (new ConsultarProcessosService())->consultarPagina(
        tribunalConsultarProcessos(),
        NUMERO_PROCESSO_CONSULTAR_PROCESSOS,
        $ids,
        2,
        20,
        '{LOGIN}',
        '{SENHA}',
    );

    expect($this->chamadas->requisicoes)->toHaveCount(1)
        ->and($this->chamadas->requisicoes[0]['operacao'])->toBe('consultarProcessos')
        ->and($this->chamadas->requisicoes[0]['parametros'])->toBe([
            'idConsultante' => '{LOGIN}',
            'senhaConsultante' => '{SENHA}',
            'numeroProcesso' => NUMERO_PROCESSO_CONSULTAR_PROCESSOS,
            'movimentos' => false,
            'incluirCabecalho' => false,
            'incluirDocumentos' => false,
            'documento' => array_map('strval', $ids),
            'pagina' => 2,
            'tamanhoPagina' => 20,
        ]);
});

it('envia documento como lista de ids string mesmo com chaves soltas', function () {
    simularIntegracaoConsultarProcessos($this->chamadas, fn () => respostaConsultarProcessos((object) []));

    consultarPaginaPadrao([3 => 7001, 9 => '7002']);

    expect($this->chamadas->requisicoes[0]['parametros']['documento'])->toBe(['7001', '7002']);
});

it('chama url_webservice_mni e nao url_webservice_mni_consultar_processo', function () {
    simularIntegracaoConsultarProcessos($this->chamadas, fn () => respostaConsultarProcessos((object) []));

    consultarPaginaPadrao();

    expect($this->chamadas->urls)->toBe([URL_MNI_CONSULTAR_PROCESSOS])
        ->and($this->chamadas->urls)->not->toContain(URL_CONSULTAR_PROCESSO_CONSULTAR_PROCESSOS);
});

it('usa as credenciais informadas', function () {
    simularIntegracaoConsultarProcessos($this->chamadas, fn () => respostaConsultarProcessos((object) []));

    consultarPaginaPadrao(login: '{LOGIN}', senha: '{SENHA}');

    expect($this->chamadas->requisicoes[0]['parametros']['idConsultante'])->toBe('{LOGIN}')
        ->and($this->chamadas->requisicoes[0]['parametros']['senhaConsultante'])->toBe('{SENHA}');
});

it('usa as credenciais do tribunal quando login e senha nao sao informados', function () {
    simularIntegracaoConsultarProcessos($this->chamadas, fn () => respostaConsultarProcessos((object) []));

    consultarPaginaPadrao();

    expect($this->chamadas->requisicoes[0]['parametros']['idConsultante'])->toBe('login-do-tribunal')
        ->and($this->chamadas->requisicoes[0]['parametros']['senhaConsultante'])->toBe('{SENHA}');
});

// consultarPagina: formas de resposta -------------------------------------------

it('devolve mapa por id para documento unico como objeto', function () {
    simularIntegracaoConsultarProcessos($this->chamadas, fn () => respostaConsultarProcessos(
        (object) ['documento' => documentoMniConsultarProcessos(5001)]
    ));

    $mapa = consultarPaginaPadrao();

    expect(chavesConsultarProcessos($mapa))->toBe(['5001'])
        ->and($mapa[(string) 5001]->idDocumento)->toBe(5001);
});

it('devolve mapa por id para documentos em array, filhos diretos de processos', function () {
    simularIntegracaoConsultarProcessos($this->chamadas, fn () => respostaConsultarProcessos(
        (object) ['documento' => [documentoMniConsultarProcessos('5001'), documentoMniConsultarProcessos('5002')]]
    ));

    $mapa = consultarPaginaPadrao(['5001', '5002']);

    expect(chavesConsultarProcessos($mapa))->toBe(['5001', '5002'])
        ->and($mapa['5002']->conteudo)->toBe('%PDF-cru-5002');
});

it('inclui documentoVinculado no mapa', function () {
    simularIntegracaoConsultarProcessos($this->chamadas, fn () => respostaConsultarProcessos(
        (object) ['documento' => documentoMniConsultarProcessos(5001, [
            'documentoVinculado' => [documentoMniConsultarProcessos(5002), documentoMniConsultarProcessos(5003)],
        ])]
    ));

    $mapa = consultarPaginaPadrao(['5001', '5002', '5003']);

    expect(chavesConsultarProcessos($mapa))->toBe(['5001', '5002', '5003']);
});

it('aceita documentos dentro de processos->processo', function () {
    simularIntegracaoConsultarProcessos($this->chamadas, fn () => respostaConsultarProcessos(
        (object) ['processo' => (object) ['documento' => [documentoMniConsultarProcessos(5001), documentoMniConsultarProcessos(5002)]]]
    ));

    $mapa = consultarPaginaPadrao(['5001', '5002']);

    expect(chavesConsultarProcessos($mapa))->toBe(['5001', '5002']);
});

it('devolve os documentos crus, sem codificar o conteudo', function () {
    simularIntegracaoConsultarProcessos($this->chamadas, fn () => respostaConsultarProcessos(
        (object) ['documento' => documentoMniConsultarProcessos(5001)]
    ));

    $mapa = consultarPaginaPadrao();

    expect($mapa['5001']->conteudo)->toBe('%PDF-cru-5001');
});

it('devolve mapa vazio quando processos vem sem documento', function () {
    simularIntegracaoConsultarProcessos($this->chamadas, fn () => respostaConsultarProcessos((object) []));

    expect(consultarPaginaPadrao())->toBe([]);
});

it('devolve mapa vazio quando a resposta vem sem processos', function () {
    simularIntegracaoConsultarProcessos($this->chamadas, fn () => (object) ['sucesso' => true, 'mensagem' => 'ok']);

    expect(consultarPaginaPadrao())->toBe([]);
});

// consultarPagina: erros ---------------------------------------------------------

it('lanca ConsultaMNISemSucessoException quando o MNI responde sucesso false', function () {
    simularIntegracaoConsultarProcessos($this->chamadas, fn () => (object) [
        'sucesso' => false,
        'mensagem' => 'Processo não encontrado',
    ]);

    $capturada = null;

    try {
        consultarPaginaPadrao();
    } catch (Throwable $e) {
        $capturada = $e;
    }

    expect($capturada)->toBeInstanceOf(ConsultaMNISemSucessoException::class)
        ->and($capturada->getError())->toBe('Processo não encontrado');
});

it('transforma SoapFault em MNIException nao especifica', function () {
    simularIntegracaoConsultarProcessos($this->chamadas, fn () => throw new SoapFault('Server', 'Could not connect to host'));

    $capturada = null;

    try {
        consultarPaginaPadrao();
    } catch (Throwable $e) {
        $capturada = $e;
    }

    expect($capturada)->toBeInstanceOf(MNIException::class)
        ->and($capturada)->not->toBeInstanceOf(ConsultaMNISemSucessoException::class)
        ->and($capturada->getError())->toBe('Could not connect to host')
        ->and($capturada->getCode())->toBe(500);
});

it('transforma erro de recibo do IntegracaoBase em MNIException nao especifica', function () {
    simularIntegracaoConsultarProcessos($this->chamadas, fn () => throw new Exception('Recibo com erro', 99));

    expect(fn () => consultarPaginaPadrao())
        ->toThrow(fn (MNIException $e) => expect($e)->not->toBeInstanceOf(ConsultaMNISemSucessoException::class)
            ->and($e->getError())->toBe('Recibo com erro'));
});

it('transforma senha do tribunal indecifravel em MNIException 422 sem chamar o MNI', function () {
    simularIntegracaoConsultarProcessos($this->chamadas, fn () => respostaConsultarProcessos((object) []));

    $capturada = null;

    try {
        consultarPaginaPadrao(tribunal: ['password' => 'texto-puro']);
    } catch (Throwable $e) {
        $capturada = $e;
    }

    expect($capturada)->toBeInstanceOf(MNIException::class)
        ->and($capturada)->not->toBeInstanceOf(ConsultaMNISemSucessoException::class)
        ->and($capturada->getError())->toBe('Credenciais MNI/PJ-e tribunal inválidas.')
        ->and($capturada->getCode())->toBe(422)
        ->and($this->chamadas->requisicoes)->toBe([]);
});

it('nao registra log nas falhas, para nunca expor senha nem lastRequest', function (Closure $responder) {
    Log::spy();
    simularIntegracaoConsultarProcessos($this->chamadas, $responder);

    try {
        consultarPaginaPadrao(login: '{LOGIN}', senha: '{SENHA}');
    } catch (MNIException) {
    }

    foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $nivel) {
        Log::shouldNotHaveReceived($nivel);
    }
})->with([
    'sucesso false' => [fn () => fn () => (object) ['sucesso' => false, 'mensagem' => 'Processo não encontrado']],
    'SoapFault' => [fn () => fn () => throw new SoapFault('Server', 'Internal Error')],
]);

// operacaoDisponivel --------------------------------------------------------------

it('operacaoDisponivel verifica consultarProcessos em url_webservice_mni', function (bool $disponivel) {
    simularIntegracaoConsultarProcessos($this->chamadas, possuiOperacao: fn () => $disponivel);

    expect((new ConsultarProcessosService())->operacaoDisponivel(tribunalConsultarProcessos()))->toBe($disponivel)
        ->and($this->chamadas->operacoesVerificadas)->toBe(['consultarProcessos'])
        ->and($this->chamadas->urls)->toBe([URL_MNI_CONSULTAR_PROCESSOS])
        ->and($this->chamadas->requisicoes)->toBe([]);
})->with([
    'presente' => [true],
    'ausente' => [false],
]);

it('operacaoDisponivel transforma falha ao carregar o WSDL em MNIException nao especifica', function () {
    simularIntegracaoConsultarProcessos($this->chamadas, possuiOperacao: fn () => throw new SoapFault('WSDL', "SOAP-ERROR: Parsing WSDL: Couldn't load"));

    expect(fn () => (new ConsultarProcessosService())->operacaoDisponivel(tribunalConsultarProcessos()))
        ->toThrow(fn (MNIException $e) => expect($e)->not->toBeInstanceOf(ConsultaMNISemSucessoException::class));
});

it('operacaoDisponivel le o WSDL real do tribunal', function (string $wsdl, bool $esperado) {
    $tribunal = tribunalConsultarProcessos(['url_webservice_mni' => base_path("tests/Fixtures/mni/{$wsdl}.wsdl")]);

    expect((new ConsultarProcessosService())->operacaoDisponivel($tribunal))->toBe($esperado);
})->with([
    'com consultarProcessos' => ['intercomunicacao-com-consultarProcessos', true],
    'sem consultarProcessos' => ['intercomunicacao-sem-consultarProcessos', false],
]);
