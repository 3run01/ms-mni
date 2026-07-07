<?php

namespace App\Services\MNI\Complementar;

use App\Exceptions\MNIException;
use App\Services\IntegracaoBase;

class ConsultarCompetenciaService
{
    public function execute($url, $jurisdicao_codigo, $classe_codigo, $assunto_codigo)
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

        if (!$assunto_codigo) {
            throw new MNIException('Informe o código do assunto.', 422);
        }


        $integracao = new IntegracaoBase($url);
        $retorno = $integracao->makeSoapRequest('consultarCompetencias', [
            'arg0' => [
                'id' => $jurisdicao_codigo,
            ],
            'arg1' => [
                'codigo' => $classe_codigo
            ],
            'arg2' => [
                'codigo' => $assunto_codigo
            ]
        ]);
        if (!empty($retorno->return)) {
            $array = is_array($retorno->return) ? $retorno->return : [$retorno->return];
            return $array;
        } else {
            throw new MNIException('Nenhuma competencia foi encontrada.', 404);
        }
    }
}
