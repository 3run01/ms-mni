<?php

namespace App\Services\MNI\Complementar;

use App\Exceptions\MNIException;
use App\Services\IntegracaoBase;

class ConsultarOrgaoJulgadorService
{
    public function execute($url)
    {
        $integracao = new IntegracaoBase($url);
        $retorno = $integracao->makeSoapRequest('consultarOrgaosJulgadores', []);
        return $retorno->return;
    }
}
