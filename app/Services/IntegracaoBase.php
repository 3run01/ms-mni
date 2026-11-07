<?php

namespace App\Services;

use App\Services\SoapClientMNI;
use Exception;
use App\Exceptions\MNIException;

class IntegracaoBase
{
    protected $soap;
    protected $url;
    protected $lastRequest;

    public function __construct($url)
    {
        $this->url = $url;
    }

    protected function initiateSoap()
    {
        // try {
        $opts = [
            'http' => [
                'user_agent' => 'PHPSoapClient',
                'timeout' => 1800
            ]
        ];

        $context = stream_context_create($opts);

        $arrOpt = [
            'stream_context' => $context,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'encoding' => 'UTF-8',
            'compression' => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP | SOAP_COMPRESSION_DEFLATE,
            // 'trace' => 1
        ];

        $this->soap = new SoapClientMNI($this->url, $arrOpt);
        // } catch (Exception $e) {
        // throw new MNIException('MNI Indisponível.', 500);
        // }
    }

    /**
     * `$params` leva o par do PJe (`idConsultante`/`senhaConsultante`).
     * #[\SensitiveParameter] nele e em todo parâmetro de login e senha da
     * cadeia MNI (services MNI e de Processo): com
     * `zend.exception_ignore_args = 0`, o trace de uma exceção como texto
     * (getTraceAsString, log, failed_jobs) mostra os argumentos de cada
     * chamada, e o par sairia em texto claro. Com o atributo, o PHP troca o
     * valor por SensitiveParameterValue no trace.
     */
    public function makeSoapRequest($function, #[\SensitiveParameter] $params)
    {
        $this->initiateSoap();

        $retorno = $this->soap->$function($params);
        $this->lastRequest = $this->soap->getLastRequest();

        if (isset($retorno->recibo->sucesso) && !$retorno->recibo->sucesso) {
            throw new Exception($retorno->recibo->mensagens->descritivo, (int)$retorno->recibo->mensagens->codigoUnico);
        } elseif (isset($retorno->recibo->recibo->sucesso) && !$retorno->recibo->recibo->sucesso) {
            throw new Exception($retorno->recibo->recibo->mensagens->descritivo, (int)$retorno->recibo->recibo->mensagens->codigoUnico);
        }

        return $retorno;
    }

    /**
     * Diz se o WSDL expõe a operação, sem invocá-la: chamar uma operação
     * inexistente no SoapClient vira SoapFault, que não se distingue de falha
     * de transporte.
     *
     * O nome casa exato (`consultarProcesso` não casa com `consultarProcessos`
     * e vice-versa). Falha ao carregar o WSDL propaga para o chamador.
     */
    public function possuiOperacao(string $operacao): bool
    {
        $this->initiateSoap();

        $padrao = '/\s' . preg_quote($operacao, '/') . '\(/';

        foreach ($this->soap->__getFunctions() ?? [] as $assinatura) {
            if (preg_match($padrao, $assinatura)) {
                return true;
            }
        }

        return false;
    }

    public function getLastRequest()
    {
        return $this->lastRequest;
    }
}
