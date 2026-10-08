<?php

use App\Exceptions\MNIException;
use App\Models\Tribunal;
use App\Services\MNI\Intercomunicacao\ConsultarProcessoService;
use Illuminate\Support\Facades\Crypt;

const URL_MNI_CONSULTAR_PROCESSO_SERVICE = 'https://mni.tribunal.exemplo/intercomunicacao?wsdl';
const URL_CONSULTAR_PROCESSO_CONSULTAR_PROCESSO_SERVICE = 'https://consulta.tribunal.exemplo/intercomunicacao?wsdl';
const NUMERO_PROCESSO_CONSULTAR_PROCESSO_SERVICE = '0000001-02.2024.8.26.0001';

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
