<?php

namespace App\Services\MNI\Intercomunicacao;

use App\Exceptions\MNIException;
use App\Models\Tribunal;
use App\Services\IntegracaoBase;

class ConsultarProcessoService
{

    public function execute(
        Tribunal $tribunal,
        $numero_processo,
        $login_pje = null,
        $senha_pje = null,
        $incluir_documentos = true,
        $incluir_cabecalho = true,
        $incluir_movimentos = true,
        $data_referencia = null,
    ) {
        if ($data_referencia) {
            $data_referencia = date('Ymd', strtotime($data_referencia)) . '000001';
        }


        try {
            $params = [
                'idConsultante' => $login_pje,
                'senhaConsultante' => $senha_pje,
                'numeroProcesso'  => $numero_processo,
                'dataReferencia' => $data_referencia,
                'incluirCabecalho'  => $incluir_cabecalho,
                'movimentos'  => $incluir_movimentos,
                'incluirDocumentos'  => $incluir_documentos,
            ];

            $integracao = new IntegracaoBase($tribunal->url_webservice_mni_consultar_processo);

            $retorno = $integracao->makeSoapRequest('consultarProcesso', $params);

            if ($retorno->sucesso) {
                return $retorno->processo;
            } else {
                throw new MNIException($retorno->mensagem, 500);
            }
        } catch (MNIException $e) {
            throw new MNIException($e->getError(), 500);
        } catch (\Exception $e) {
            throw new MNIException($e->getMessage(), 500);
        }
    }
}
