<?php

namespace App\Services\Processo;

use App\Models\Processo;
use App\Models\Tribunal;
use App\Services\MNI\Intercomunicacao\ConsultarProcessoService;
use Illuminate\Support\Facades\DB;

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
        #[\SensitiveParameter] $login = null,
        #[\SensitiveParameter] $senha = null,
        $data_referencia = null,
        bool $baixar_binarios = true
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
        $salvarDocumentoProcessoService->execute($processo, $retorno->documento, $login, $senha, $baixar_binarios);

        return $processo;
    }

    /**
     * Lista completa do processo para o OCR (RF-04 item 1, D8): consulta a
     * linha `$tribunal` com o par do pedido e grava dados básicos, partes,
     * movimentos e todos os documentos.
     *
     * Difere de consultarNumero em dois pontos, de propósito:
     * - `dataReferencia` nulo, sem o recorte de 90 dias: o documento antigo
     *   que ainda não está no banco também entra no OCR, e os novos no
     *   tribunal entram a cada processamento;
     * - grava sem baixar binários: o download do OCR sai pela fila própria,
     *   cifrada com o par do dono (RF-09), e não em paralelo pelos jobs de
     *   `mni-download`.
     *
     * O par é obrigatório: o OCR nunca cai no par padrão nem na credencial do
     * tribunal (RF-02). A recusa do par e o processo inexistente sobem como
     * CredencialPjeRecusadaException e ProcessoNaoEncontradoNoTribunalException,
     * sem embrulho e antes de qualquer gravação.
     */
    public function consultarListaCompletaParaOcr(
        Tribunal $tribunal,
        string $numero,
        #[\SensitiveParameter] string $login,
        #[\SensitiveParameter] string $senha,
    ): Processo {
        $retorno = $this->consultarProcessoService->execute(
            $tribunal,
            $numero,
            $login,
            $senha,
            true,
            true,
            true,
            null,
        );

        // Numa transação: as contagens do 202 saem logo depois desta
        // gravação, e uma lista gravada pela metade daria contagens erradas.
        return DB::transaction(function () use ($tribunal, $retorno) {
            $processo = $this->salvarDadosBasicosProcessoService->execute($tribunal, $retorno->dadosBasicos);
            $this->salvarParteProcessoService->execute($processo, $retorno->dadosBasicos->polo ?? []);
            $this->salvarMovimentoProcessoService->execute($processo, $retorno->movimento ?? []);
            // Sem o par: com baixar_binarios = false, nada é enfileirado.
            $this->salvarDocumentoProcessoService->execute($processo, $retorno->documento ?? [], null, null, false);

            return $processo;
        });
    }

    public function consultarDadosBasicos(
        $tribunal,
        $numero_processo,
        #[\SensitiveParameter] $login = null,
        #[\SensitiveParameter] $senha = null,
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
        #[\SensitiveParameter] $login = null,
        #[\SensitiveParameter] $senha = null,
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
        #[\SensitiveParameter] $login = null,
        #[\SensitiveParameter] $senha = null,
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
        $this->salvarDocumentoProcessoService->execute($processo, $retorno->documento, $login, $senha);
        return $processo;
    }

    public function getProcesso(
        $tribunal,
        $numero_processo,
        #[\SensitiveParameter] $login = null,
        #[\SensitiveParameter] $senha = null
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
