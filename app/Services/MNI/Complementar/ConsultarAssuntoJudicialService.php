<?php

namespace App\Services\MNI\Complementar;

use App\Exceptions\MNIException;
use App\Services\IntegracaoBase;

class ConsultarAssuntoJudicialService
{
    public function execute($url, $jurisdicao_codigo, $classe_codigo)
    {
        if (!$url) {
            throw new MNIException('Informe o url do webservice.', 422);
        }

        if ($jurisdicao_codigo === '') {
            throw new MNIException('Informe o código da jurisdição.', 422);
        }

        if (!$classe_codigo) {
            throw new MNIException('Informe o código da classe.', 422);
        }
        $integracao = new IntegracaoBase($url);
        $retorno = $integracao->makeSoapRequest('consultarAssuntosJudiciais', [
            'arg0' => [
                'id' => $jurisdicao_codigo,
            ],
            'arg1' => [
                'codigo' => $classe_codigo
            ]
        ]);



        if (!empty($retorno->return)) {
            return is_array($retorno->return) ? $retorno->return : array($retorno->return);
        } else {
            throw new MNIException('Nenhum assunto foi encontrada.', 404);
        }
    }
}
