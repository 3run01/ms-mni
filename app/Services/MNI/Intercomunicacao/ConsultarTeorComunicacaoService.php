<?php

namespace App\Services\MNI\Intercomunicacao;

use App\Exceptions\MNIException;
use App\Models\Tribunal;
use App\Services\IntegracaoBase;

class ConsultarTeorComunicacaoService
{
    public function execute(Tribunal $tribunal, $login, $senha, $payload)
    {
        $payload['idConsultante'] = $login;
        $payload['senhaConsultante'] = $senha;


        $integracao = new IntegracaoBase($tribunal->url_webservice_mni);
        $retorno = $integracao->makeSoapRequest('consultarTeorComunicacao', $payload);

        if ($retorno->sucesso == false) {
            throw new MNIException($retorno->mensagem, 500);
        }

        if (!isset($retorno->comunicacao)) {
            throw new MNIException($retorno->mensagem, 500);
        }

        return $retorno;
    }
}
