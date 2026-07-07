<?php

namespace App\Services\MNI\Intercomunicacao;

use App\Models\Tribunal;
use App\Services\IntegracaoBase;
use Illuminate\Support\Facades\Crypt;

class ValidarCredencialService
{
    public function execute(Tribunal $tribunal, $login, $senha)
    {
        $params = [
            'idConsultante' => $login,
            'senhaConsultante' => $senha,
            'numeroProcesso'  => '00000000000000000000',
            'incluirCabecalho'  => false,
            'movimentos'  => false,
            'incluirDocumentos'  => false,
        ];

        $integracao = new IntegracaoBase($tribunal->url_webservice_mni);
        $retorno = $integracao->makeSoapRequest('consultarProcesso', $params);

        if (strpos($retorno->mensagem, 'Erro ao realizar login via MNI') !== false) {
            return ['sucesso' => false, 'mensagem' => 'Credenciais inválidas.'];
        }

        return ['sucesso' => true, 'mensagem' => 'Login efetuado com sucesso.'];
    }

}
