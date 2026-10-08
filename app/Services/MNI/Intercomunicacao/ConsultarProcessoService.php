<?php

namespace App\Services\MNI\Intercomunicacao;

use App\Exceptions\CredencialPjeRecusadaException;
use App\Exceptions\MNIException;
use App\Exceptions\ProcessoNaoEncontradoNoTribunalException;
use App\Models\Tribunal;
use App\Services\IntegracaoBase;
use App\Services\MNI\ClassificarFalhaMniService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

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

        // Sem par informado a chamada usa a credencial do tribunal, cuja recusa
        // não é do consultante e por isso não é classificada.
        $credencialInformada = $login_pje !== null && $senha_pje !== null;

        try {
            $params = [
                'idConsultante' => $login_pje ?? $tribunal->login,
                'senhaConsultante' => $senha_pje ?? Crypt::decrypt($tribunal->password),
                'numeroProcesso'  => $numero_processo,
                'dataReferencia' => $data_referencia,
                'incluirCabecalho'  => $incluir_cabecalho,
                'movimentos'  => $incluir_movimentos,
                'incluirDocumentos'  => $incluir_documentos,
            ];

            // Resolvida pelo container para que os testes troquem o cliente SOAP por binding.
            $integracao = app()->makeWith(IntegracaoBase::class, ['url' => $tribunal->url_webservice_mni_consultar_processo]);

            $retorno = $integracao->makeSoapRequest('consultarProcesso', $params);

            if ($retorno->sucesso) {
                return $retorno->processo;
            } elseif ($credencialInformada && ClassificarFalhaMniService::credencialRecusada($retorno->mensagem)) {
                throw new CredencialPjeRecusadaException($retorno->mensagem, 500);
            } elseif (ClassificarFalhaMniService::processoNaoEncontrado($retorno->mensagem)) {
                throw new ProcessoNaoEncontradoNoTribunalException($retorno->mensagem, 500);
            } else {
                throw new MNIException($retorno->mensagem, 500);
            }
        } catch (DecryptException $e) {
            throw new MNIException('Credenciais MNI/PJ-e tribunal inválidas.', 422);
        } catch (CredencialPjeRecusadaException|ProcessoNaoEncontradoNoTribunalException $e) {
            // Sem re-embrulhar: o catch de MNIException abaixo apagaria o tipo
            // que a camada HTTP usa para responder com código estável.
            throw $e;
        } catch (MNIException $e) {
            throw new MNIException($e->getError(), 500);
        } catch (\Exception $e) {
            if ($credencialInformada && ClassificarFalhaMniService::credencialRecusada($e->getMessage())) {
                throw new CredencialPjeRecusadaException($e->getMessage(), 500);
            }

            throw new MNIException($e->getMessage(), 500);
        }
    }
}
