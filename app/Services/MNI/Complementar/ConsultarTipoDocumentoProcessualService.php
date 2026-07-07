<?php

namespace App\Services\MNI\Complementar;

use App\Services\IntegracaoBase;

class ConsultarTipoDocumentoProcessualService
{

    public function execute($url)
    {
        $integracao = new IntegracaoBase($url);
        $retorno = $integracao->makeSoapRequest('consultarTodosTiposDocumentoProcessual', []);

        return $retorno->return;
    }
}
