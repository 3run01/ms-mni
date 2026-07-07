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

    public function makeSoapRequest($function, $params)
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

    public function getLastRequest()
    {
        return $this->lastRequest;
    }
}
