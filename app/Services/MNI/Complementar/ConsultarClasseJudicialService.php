<?php

namespace App\Services\MNI\Complementar;

use App\Exceptions\MNIException;
use App\Services\IntegracaoBase;

class ConsultarClasseJudicialService
{
    public function execute($url, $jurisdicao_codigo)
    {
        if (!$url) {
            throw new MNIException('Informe o url do webservice.', 422);
        }

        if ($jurisdicao_codigo === '') {
            throw new MNIException('Informe o código da jurisdição.', 422);
        }


        $payload = [
            "arg0" => ["id" => $jurisdicao_codigo]
        ];


        $integracao = new IntegracaoBase($url);
        $retorno = $integracao->makeSoapRequest('consultarClassesJudiciais', $payload);

        // dd($retorno);
        if (!empty($retorno->return)) {
            return is_array($retorno->return) ? $retorno->return : array($retorno->return);
        } else {
            throw new MNIException('Nenhuma classe foi encontrada.', 404);
        }
    }
}
