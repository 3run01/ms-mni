<?php

namespace App\Services\MNI\Complementar;
use App\Services\IntegracaoBase;
class ConsultarPrioridadeProcessoService
{
    public function execute($url){
            $integracao = new IntegracaoBase($url);
            $retorno = $integracao->makeSoapRequest('consultarPrioridadeProcesso', []);
            return $retorno->return;
    }

}
