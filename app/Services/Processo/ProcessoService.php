<?php

namespace App\Services\Processo;

use App\Models\Processo;
use App\Services\MNI\Intercomunicacao\ConsultarProcessoService;

class ProcessoService
{

    public $consultarProcessoService;
    public $salvarParteProcessoService;
    public $salvarMovimentoProcessoService;
    public $salvarDocumentoProcessoService;
    public $salvarDadosBasicosProcessoService;

    public function __construct()
    {
        $this->consultarProcessoService = new ConsultarProcessoService();
        $this->salvarParteProcessoService = new SalvarParteProcessoService();
        $this->salvarMovimentoProcessoService = new SalvarMovimentoProcessoService();
        $this->salvarDocumentoProcessoService = new SalvarDocumentoProcessoService();
        $this->salvarDadosBasicosProcessoService = new SalvarDadosBasicoProcessoService();
    }

    public function consultarNumero(
        $tribunal,
        $numero_processo,
        $login = null,
        $senha = null,
        $data_referencia = null
    ) {
        // Se data_referencia não foi fornecida, usar padrão de 90 dias atrás
        if ($data_referencia === null) {
            $data_referencia = date('Ymd', strtotime("-90 days")) . '000001';
        }

        // dd($data_referencia);

        $retorno = $this->consultarProcessoService->execute(
            $tribunal,
            $numero_processo,
            $login,
            $senha,
            true,
            true,
            true,
            $data_referencia
        );

        $salvarDadosBasicosProcessoService = new SalvarDadosBasicoProcessoService();
        $processo = $salvarDadosBasicosProcessoService->execute($tribunal, $retorno->dadosBasicos);

        $salvarParteProcessoService = new SalvarParteProcessoService();
        $salvarParteProcessoService->execute($processo, $retorno->dadosBasicos->polo);

        $salvarMovimentoProcessoService = new SalvarMovimentoProcessoService();
        $salvarMovimentoProcessoService->execute($processo, $retorno->movimento);

        $salvarDocumentoProcessoService = new SalvarDocumentoProcessoService();
        $salvarDocumentoProcessoService->execute($processo, $retorno->documento);

        return $processo;
    }

    public function consultarDadosBasicos(
        $tribunal,
        $numero_processo,
        $login = null,
        $senha = null,
    ) {
        $retorno = $this->consultarProcessoService->execute(
            $tribunal,
            $numero_processo,
            $login,
            $senha,
            false,
            true,
            false
        );

        // dd($retorno);

        $processo = $this->salvarDadosBasicosProcessoService->execute($tribunal, $retorno->dadosBasicos);
        $this->salvarParteProcessoService->execute($processo, $retorno->dadosBasicos->polo);

        return $processo->load('tribunal', 'classe', 'assuntos', 'prioridades', 'partes');
    }

    public function consultarMovimentos(
        $tribunal,
        $numero_processo,
        $login = null,
        $senha = null,
        $data_referencia = null,
    ) {
        $retorno = $this->consultarProcessoService->execute(
            $tribunal,
            $numero_processo,
            $login,
            $senha,
            false,
            false,
            true,
            $data_referencia,
        );

        $processo = $this->getProcesso($tribunal, $numero_processo, $login, $senha);
        $this->salvarMovimentoProcessoService->execute($processo, $retorno->movimento);

        return $processo;
    }

    public function consultarDocumentos(
        $tribunal,
        $numero_processo,
        $login = null,
        $senha = null,
        $data_referencia = null,
    ) {
        $retorno = $this->consultarProcessoService->execute(
            $tribunal,
            $numero_processo,
            $login,
            $senha,
            true,
            false,
            false,
            $data_referencia,
        );

        $processo = $this->getProcesso($tribunal, $numero_processo, $login, $senha);
        $this->salvarDocumentoProcessoService->execute($processo, $retorno->documento);
        return $processo;
    }

    public function getProcesso(
        $tribunal,
        $numero_processo,
        $login = null,
        $senha = null
    ) {
        //verifica se o processo existe
        $processo = Processo::where('numero_processo', $numero_processo)
            ->where('tribunal_id', $tribunal->id)
            ->first();

        //se nao existir, consulta os dados basicos e salva no banco de dados
        if (!$processo) {
            $processo = $this->consultarDadosBasicos($tribunal, $numero_processo, $login, $senha);
        }

        return $processo;
    }
}
