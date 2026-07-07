<?php

namespace App\Services\MNI\Intercomunicacao;

use App\Models\Expediente;
use App\Models\Jurisdicao;
use App\Models\Unidade;
use App\Services\IntegracaoBase;
use App\Models\Tribunal;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use App\Exceptions\MNIException;
use App\Jobs\BaixarProcessoMNIJob;
use App\Services\MNI\Intercomunicacao\ConsultarProcessoService;
// use App\Traits\ProcessoHistoricoTrait;

class ConsultarExpedienteService
{
    // use ProcessoHistoricoTrait;

    public $tribunal;

    public function execute()
    {
        $tribunais = Tribunal::where('ativo', true)->get();
        foreach ($tribunais as $tribunal) {
            $integracao = new IntegracaoBase($tribunal->url_webservice_mni);
            $retorno = $integracao->makeSoapRequest('consultarAvisosPendentes', [
                'idConsultante' => $tribunal->login,
                'senhaConsultante' => Crypt::decrypt($tribunal->password),
                // 'idRepresentado' => env('CNPJ_REPRESENTADO'),
                'tipoPendencia' => 'PC'
            ]);

            if ($retorno->sucesso == false) {
                // dd($tribunal->nome, $retorno->mensagem);
                throw new MNIException($retorno->mensagem, 500);
            }

            $expedientes = [];

            if (isset($retorno->aviso)) {
                $expedientes = is_array($retorno->aviso) ? $retorno->aviso : array($retorno->aviso);
            }

            if ($expedientes) {
                foreach ($expedientes as $expediente) {
                    BaixarProcessoMNIJob::dispatch($tribunal, $expediente->processo->numero);
                }
            }

            // $this->salvar($expedientes, $tribunal);
        }
        //        return $expedientes;
    }

    // public function salvar($expedientes, $tribunal)
    // {
    //     foreach ($expedientes as $key => $expediente) {
    //         $expedienteExistente = Expediente::where('aviso_id', $expediente->idAviso)->count();

    //         if (!empty($expedienteExistente) == 0) {
    //             // $jurisdicao = Jurisdicao::where('tribunal_id', $tribunal->id)->where('codigo_ibge', $expediente->processo->orgaoJulgador->codigoMunicipioIBGE)->first();
    //             $unidades = Unidade::where('codigo_ibge', $expediente->processo->orgaoJulgador->codigoMunicipioIBGE)->get();

    //             // if (!empty($jurisdicao)) {
    //             //     Unidade::where('jurisdicao_codigo', $jurisdicao->codigo)->get();
    //             // }

    //             // $jurisdicaoPadrao = Jurisdicao::where('codigo_ibge', '1600303')->first();

    //             $data_disponibilizacao = Carbon::createFromFormat('YmdHis', $expediente->dataDisponibilizacao)->format('Y-m-d');
    //             $data_prazo_ciencia = date('Y-m-d', strtotime('+10 days', strtotime($data_disponibilizacao)));
    //             //                $prioridade = $this->obterPrioridades($tribunal, $expediente->processo->numero);
    //             //                dd($prioridade);

    //             $novoExpediente = Expediente::updateOrCreate([
    //                 'aviso_id' => $expediente->idAviso,
    //                 'tribunal_id' => $tribunal->id
    //             ], [
    //                 'unidade_id' => count($unidades) > 1 ? null : $unidades[0]->id,
    //                 'numero_processo' => $expediente->processo->numero,
    //                 'status' => Expediente::STATUS_PENDENTE_CIENCIA,
    //                 'tipo_comunicacao' => Expediente::tiposComunicacao()[$expediente->tipoComunicacao],
    //                 'data_disponibilizacao' => $data_disponibilizacao,
    //                 'data_prazo_ciencia' => $data_prazo_ciencia,
    //                 'data_prazo_final' => $data_prazo_ciencia,
    //                 'orgao_codigo' => $expediente->processo->orgaoJulgador ? $expediente->processo->orgaoJulgador->codigoOrgao : null,
    //                 'classe_codigo' => $expediente->processo->classeProcessual ? $expediente->processo->classeProcessual : null,
    //                 'assunto_codigo' => !empty($expediente->processo->assunto) ? $expediente->processo->assunto->codigoNacional : null,
    //                 // "jurisdicao_id" => $jurisdicao->id ?? $jurisdicaoPadrao->id,
    //                 'codigo_ibge' => $expediente->processo->orgaoJulgador->codigoMunicipioIBGE ? $expediente->processo->orgaoJulgador->codigoMunicipioIBGE : null,
    //                 //                    'prioridade' => $prioridade
    //             ]);

    //             $this->registrarHistorico($novoExpediente, 'Expediente recebido via MNI/PJ-e');
    //         }
    //     }
    // }

    // public function obterPrioridades($tribunal, $numero_processo)
    // {
    //     $consultarProcessoService = new ConsultarProcessoService();
    //     $processo = $consultarProcessoService->execute($tribunal, $numero_processo);
    //     $dadosBasicos = $processo->dadosBasicos;
    //     $prioridades = is_array($dadosBasicos->prioridade) ? $dadosBasicos->prioridade : array($dadosBasicos->prioridade);
    //     return $prioridades;
    // }
}
