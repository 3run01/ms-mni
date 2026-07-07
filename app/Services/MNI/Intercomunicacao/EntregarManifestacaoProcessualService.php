<?php

namespace app\Services\MNI\Intercomunicacao;

use App\Exceptions\MNIException;
use App\Services\IntegracaoBase;
use Illuminate\Support\Facades\Crypt;
use App\Models\Tribunal;

class EntregarManifestacaoProcessualService
{
    public $payloadEnvio;

    public function execute(Tribunal $tribunal, $login, $senha, $payload)
    {
        $payload['idManifestante'] = $login;
        $payload['senhaManifestante'] = $senha;
        $integracao = new IntegracaoBase($tribunal->url_webservice_mni);
        $retorno = $integracao->makeSoapRequest('entregarManifestacaoProcessual', $payload);
        $this->payloadEnvio = $integracao->getLastRequest();

        // if ($retorno->sucesso == false) {
        //     throw new MNIException($retorno->mensagem, 500);
        // }

        return $retorno;
    }

    public function getPayloadEnvio()
    {
        return $this->payloadEnvio;
    }
}
