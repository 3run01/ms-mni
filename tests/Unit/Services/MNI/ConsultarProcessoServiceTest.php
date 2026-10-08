<?php

use App\Exceptions\CredencialPjeRecusadaException;
use App\Exceptions\MNIException;
use App\Exceptions\ProcessoNaoEncontradoNoTribunalException;
use App\Models\Tribunal;
use App\Services\MNI\Intercomunicacao\ConsultarProcessoService;
use Illuminate\Support\Facades\Crypt;

const URL_MNI_CONSULTAR_PROCESSO_SERVICE = 'https://mni.tribunal.exemplo/intercomunicacao?wsdl';
const URL_CONSULTAR_PROCESSO_CONSULTAR_PROCESSO_SERVICE = 'https://consulta.tribunal.exemplo/intercomunicacao?wsdl';
const NUMERO_PROCESSO_CONSULTAR_PROCESSO_SERVICE = '0000001-02.2024.8.26.0001';
const LOGIN_RECUSADO_CONSULTAR_PROCESSO_SERVICE = 'Erro ao realizar login via MNI. exception invoking: loginFailed';
const FAULT_LOGIN_RECUSADO_CONSULTAR_PROCESSO_SERVICE = 'org.apache.cxf.interceptor.Fault: exception invoking: loginFailed';
const PROCESSO_NAO_ENCONTRADO_CONSULTAR_PROCESSO_SERVICE = 'Processo de número 99999901120268030001 não encontrado!';

function tribunalConsultarProcessoService(array $atributos = []): Tribunal
{
    return Tribunal::factory()->make(array_merge([
        'login' => 'login-do-tribunal',
        'password' => Crypt::encrypt('{SENHA}'),
        'url_webservice_mni' => URL_MNI_CONSULTAR_PROCESSO_SERVICE,
        'url_webservice_mni_consultar_processo' => URL_CONSULTAR_PROCESSO_CONSULTAR_PROCESSO_SERVICE,
    ], $atributos));
}

function respostaConsultarProcessoService(object $processo): object
{
    return (object) [
        'sucesso' => true,
        'mensagem' => 'Processo consultado com sucesso',
        'processo' => $processo,
    ];
}

/**
 * O tribunal falha do jeito pedido: `sucesso = false` com a mensagem, ou
 * SoapFault com o texto (o fault CXF do PJe chega assim).
 */
function responderFalhaConsultarProcessoService(string $forma, string $texto): Closure
{
    return match ($forma) {
        'sucesso false' => fn () => (object) ['sucesso' => false, 'mensagem' => $texto],
        'SoapFault' => fn () => throw new SoapFault('Server', $texto),
    };
}

function falhaConsultarProcessoService(Closure $execute): ?Throwable
{
    try {
        $execute();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

it('execute resolve o IntegracaoBase pelo container com a url_webservice_mni_consultar_processo', function () {
    $processo = (object) ['dadosBasicos' => (object) ['numero' => NUMERO_PROCESSO_CONSULTAR_PROCESSO_SERVICE]];
    $simulador = simularIntegracaoMni(fn () => respostaConsultarProcessoService($processo));

    $retorno = (new ConsultarProcessoService())->execute(tribunalConsultarProcessoService(), NUMERO_PROCESSO_CONSULTAR_PROCESSO_SERVICE, '{LOGIN}', '{SENHA}');

    expect($retorno)->toBe($processo)
        ->and($simulador->urls)->toBe([URL_CONSULTAR_PROCESSO_CONSULTAR_PROCESSO_SERVICE])
        ->and($simulador->requisicoes)->toHaveCount(1)
        ->and($simulador->requisicoes[0]['operacao'])->toBe('consultarProcesso')
        ->and($simulador->requisicoes[0]['parametros'])->not->toHaveKey('documento');
});

it('execute repassa o par informado como idConsultante e senhaConsultante', function () {
    $simulador = simularIntegracaoMni(fn () => respostaConsultarProcessoService((object) []));

    (new ConsultarProcessoService())->execute(tribunalConsultarProcessoService(), NUMERO_PROCESSO_CONSULTAR_PROCESSO_SERVICE, '{LOGIN}', '{SENHA}');

    expect($simulador->requisicoes[0]['parametros'])->toBe([
        'idConsultante' => '{LOGIN}',
        'senhaConsultante' => '{SENHA}',
        'numeroProcesso' => NUMERO_PROCESSO_CONSULTAR_PROCESSO_SERVICE,
        'dataReferencia' => null,
        'incluirCabecalho' => true,
        'movimentos' => true,
        'incluirDocumentos' => true,
    ]);
});

it('execute lança MNIException 500 com o texto do tribunal quando sucesso é false', function () {
    $simulador = simularIntegracaoMni(fn () => (object) ['sucesso' => false, 'mensagem' => 'MNI indisponível']);

    $capturada = falhaConsultarProcessoService(fn () => (new ConsultarProcessoService())
        ->execute(tribunalConsultarProcessoService(), NUMERO_PROCESSO_CONSULTAR_PROCESSO_SERVICE, '{LOGIN}', '{SENHA}'));

    expect($capturada)->toBeInstanceOf(MNIException::class)
        ->and(get_class($capturada))->toBe(MNIException::class)
        ->and($capturada->getError())->toBe('MNI indisponível')
        ->and($capturada->getCode())->toBe(500)
        ->and($simulador->requisicoes)->toHaveCount(1);
});

it('execute converte SoapFault em MNIException 500 com o texto da falha', function () {
    $simulador = simularIntegracaoMni(fn () => throw new SoapFault('Server', 'Could not connect to host'));

    $capturada = falhaConsultarProcessoService(fn () => (new ConsultarProcessoService())
        ->execute(tribunalConsultarProcessoService(), NUMERO_PROCESSO_CONSULTAR_PROCESSO_SERVICE, '{LOGIN}', '{SENHA}'));

    expect($capturada)->toBeInstanceOf(MNIException::class)
        ->and(get_class($capturada))->toBe(MNIException::class)
        ->and($capturada->getError())->toBe('Could not connect to host')
        ->and($capturada->getCode())->toBe(500)
        ->and($simulador->requisicoes)->toHaveCount(1);
});

it('execute responde 422 sem chamar o MNI quando a senha do tribunal não decifra', function () {
    $simulador = simularIntegracaoMni(fn () => respostaConsultarProcessoService((object) []));

    $capturada = falhaConsultarProcessoService(fn () => (new ConsultarProcessoService())
        ->execute(tribunalConsultarProcessoService(['password' => 'texto-puro']), NUMERO_PROCESSO_CONSULTAR_PROCESSO_SERVICE));

    expect($capturada)->toBeInstanceOf(MNIException::class)
        ->and($capturada->getError())->toBe('Credenciais MNI/PJ-e tribunal inválidas.')
        ->and($capturada->getCode())->toBe(422)
        ->and($simulador->requisicoes)->toBe([]);
});

// Classificação da falha (credencial recusada e processo inexistente) ---------

it('execute lança CredencialPjeRecusadaException 500 quando o tribunal recusa o par informado', function (string $forma, string $texto) {
    $simulador = simularIntegracaoMni(responderFalhaConsultarProcessoService($forma, $texto));

    $capturada = falhaConsultarProcessoService(fn () => (new ConsultarProcessoService())
        ->execute(tribunalConsultarProcessoService(), NUMERO_PROCESSO_CONSULTAR_PROCESSO_SERVICE, '{LOGIN}', '{SENHA}'));

    expect($capturada)->toBeInstanceOf(CredencialPjeRecusadaException::class)
        ->and($capturada->getError())->toBe('Erro ao realizar login via MNI. Verifique suas credenciais.')
        ->and($capturada->getCode())->toBe(500)
        ->and($simulador->requisicoes)->toHaveCount(1);
})->with([
    'sucesso false' => ['sucesso false', LOGIN_RECUSADO_CONSULTAR_PROCESSO_SERVICE],
    'fault CXF' => ['SoapFault', FAULT_LOGIN_RECUSADO_CONSULTAR_PROCESSO_SERVICE],
]);

// Sem o par completo a chamada usa a credencial do tribunal: a recusa não é do
// consultante e segue como falha comum.
it('execute lança MNIException comum quando o tribunal recusa a credencial do tribunal', function (string $forma, string $texto, ?string $login, ?string $senha) {
    $simulador = simularIntegracaoMni(responderFalhaConsultarProcessoService($forma, $texto));

    $capturada = falhaConsultarProcessoService(fn () => (new ConsultarProcessoService())
        ->execute(tribunalConsultarProcessoService(), NUMERO_PROCESSO_CONSULTAR_PROCESSO_SERVICE, $login, $senha));

    expect(get_class($capturada))->toBe(MNIException::class)
        ->and($capturada->getError())->toBe('Erro ao realizar login via MNI. Verifique suas credenciais.')
        ->and($capturada->getCode())->toBe(500)
        ->and($simulador->requisicoes)->toHaveCount(1)
        ->and($simulador->requisicoes[0]['parametros']['idConsultante'])->toBe($login ?? 'login-do-tribunal');
})->with([
    'sucesso false sem par' => ['sucesso false', LOGIN_RECUSADO_CONSULTAR_PROCESSO_SERVICE, null, null],
    'fault CXF sem par' => ['SoapFault', FAULT_LOGIN_RECUSADO_CONSULTAR_PROCESSO_SERVICE, null, null],
    'sucesso false só com login' => ['sucesso false', LOGIN_RECUSADO_CONSULTAR_PROCESSO_SERVICE, '{LOGIN}', null],
]);

it('execute lança MNIException comum para recusa sem loginfailed mesmo com o par informado', function (string $forma) {
    $simulador = simularIntegracaoMni(responderFalhaConsultarProcessoService($forma, 'Erro ao realizar login via MNI.'));

    $capturada = falhaConsultarProcessoService(fn () => (new ConsultarProcessoService())
        ->execute(tribunalConsultarProcessoService(), NUMERO_PROCESSO_CONSULTAR_PROCESSO_SERVICE, '{LOGIN}', '{SENHA}'));

    expect(get_class($capturada))->toBe(MNIException::class)
        ->and($capturada->getError())->toBe('Erro ao realizar login via MNI. Verifique suas credenciais.')
        ->and($capturada->getCode())->toBe(500)
        ->and($simulador->requisicoes)->toHaveCount(1);
})->with(['sucesso false', 'SoapFault']);

it('execute lança ProcessoNaoEncontradoNoTribunalException 500 para o texto capturado do tribunal', function (?string $login, ?string $senha) {
    $simulador = simularIntegracaoMni(responderFalhaConsultarProcessoService('sucesso false', PROCESSO_NAO_ENCONTRADO_CONSULTAR_PROCESSO_SERVICE));

    $capturada = falhaConsultarProcessoService(fn () => (new ConsultarProcessoService())
        ->execute(tribunalConsultarProcessoService(), NUMERO_PROCESSO_CONSULTAR_PROCESSO_SERVICE, $login, $senha));

    expect($capturada)->toBeInstanceOf(ProcessoNaoEncontradoNoTribunalException::class)
        ->and($capturada->getError())->toBe(PROCESSO_NAO_ENCONTRADO_CONSULTAR_PROCESSO_SERVICE)
        ->and($capturada->getCode())->toBe(500)
        ->and($simulador->requisicoes)->toHaveCount(1);
})->with([
    'com o par informado' => ['{LOGIN}', '{SENHA}'],
    'com a credencial do tribunal' => [null, null],
]);

// O sinal de processo inexistente só vale no `sucesso = false`.
it('execute lança MNIException comum para SoapFault com o texto de processo não encontrado', function () {
    $simulador = simularIntegracaoMni(responderFalhaConsultarProcessoService('SoapFault', PROCESSO_NAO_ENCONTRADO_CONSULTAR_PROCESSO_SERVICE));

    $capturada = falhaConsultarProcessoService(fn () => (new ConsultarProcessoService())
        ->execute(tribunalConsultarProcessoService(), NUMERO_PROCESSO_CONSULTAR_PROCESSO_SERVICE, '{LOGIN}', '{SENHA}'));

    expect(get_class($capturada))->toBe(MNIException::class)
        ->and($capturada->getError())->toBe(PROCESSO_NAO_ENCONTRADO_CONSULTAR_PROCESSO_SERVICE)
        ->and($capturada->getCode())->toBe(500)
        ->and($simulador->requisicoes)->toHaveCount(1);
});

it('execute lança MNIException comum para texto fora do padrão de processo não encontrado', function (string $texto) {
    $simulador = simularIntegracaoMni(responderFalhaConsultarProcessoService('sucesso false', $texto));

    $capturada = falhaConsultarProcessoService(fn () => (new ConsultarProcessoService())
        ->execute(tribunalConsultarProcessoService(), NUMERO_PROCESSO_CONSULTAR_PROCESSO_SERVICE, '{LOGIN}', '{SENHA}'));

    expect(get_class($capturada))->toBe(MNIException::class)
        ->and($capturada->getError())->toBe($texto)
        ->and($capturada->getCode())->toBe(500)
        ->and($simulador->requisicoes)->toHaveCount(1);
})->with([
    'sem permissão de acesso' => 'Processo não encontrado ou sem permissão de acesso',
    'sem número' => 'Processo não encontrado',
    'sem exclamação' => 'Processo de número 99999901120268030001 não encontrado',
    'com prefixo' => 'Erro: Processo de número 99999901120268030001 não encontrado!',
]);
