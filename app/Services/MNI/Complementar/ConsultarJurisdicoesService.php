<?php

namespace App\Services\MNI\Complementar;

use App\Exceptions\MNIException;
use App\Services\IntegracaoBase;

class ConsultarJurisdicoesService
{
    public function execute($url)
    {
        $integracao = new IntegracaoBase($url);
        $retorno = $integracao->makeSoapRequest('consultarJurisdicoes', []);

        if (!empty($retorno->return)) {
            return is_array($retorno->return) ? $retorno->return : [$retorno->return];
        } else {
            throw new MNIException('Nenhuma jurisdição foi encontrada.', 404);
        }
    }
}
