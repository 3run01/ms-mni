<?php

namespace App\Services\MNI\Complementar;

use App\Services\IntegracaoBase;

class ConsultarTipoDocumentoProcessualService
{

    public function execute($url)
    {
        try {
            $integracao = new IntegracaoBase($url);
            $retorno = $integracao->makeSoapRequest('consultarTodosTiposDocumentoProcessual', []);
            return $retorno->return;
        } catch (\Exception $e) {
            dd($e);
        }
    }
}
