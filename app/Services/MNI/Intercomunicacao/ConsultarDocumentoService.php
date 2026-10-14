<?php

namespace App\Services\MNI\Intercomunicacao;

use App\Exceptions\ConsultaMNISemSucessoException;
use App\Exceptions\CredencialPjeRecusadaException;
use App\Exceptions\MNIException;
use App\Exceptions\ProcessoNaoEncontradoNoTribunalException;
use App\Models\Tribunal;
use App\Services\IntegracaoBase;
use App\Services\MNI\ClassificarFalhaMniService;
use App\Services\MNI\NormalizarDocumentoMniService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class ConsultarDocumentoService
{

    public function execute(
        Tribunal $tribunal,
        $numero_processo,
        $documento_id,
        $login_pje = null,
        $senha_pje = null,
    ) {
        // Sem par informado a chamada usa a credencial do tribunal, cuja recusa
        // não é do consultante e por isso não é classificada.
        $credencialInformada = $login_pje !== null && $senha_pje !== null;

        try {
            $integracao = $this->novaIntegracao($tribunal);
            $parametros = $this->parametros($tribunal, $numero_processo, $documento_id, $login_pje, $senha_pje);

            $retorno = $integracao->makeSoapRequest('consultarProcesso', $parametros);

            if ($retorno->sucesso) {

                if (!isset($retorno->processo->documento) || empty($retorno->processo->documento)) {
                    throw new MNIException('Documento não encontrado neste processo', 404);
                }

                $documentos = is_array($retorno->processo->documento) ? $retorno->processo->documento : array($retorno->processo->documento);
                $documento_retorno = null;


                foreach ($documentos as $key => $documento) {
                    $doc = is_array($documento) ? $documento : array($documento)[0];
                    if ($doc->idDocumento == $documento_id) {
                        $documento_retorno = $doc;
                    }

                    if (!empty($doc->documentoVinculado)) {
                        $documentosVinculados = is_array($doc->documentoVinculado) ? $doc->documentoVinculado : array($doc->documentoVinculado);
                        foreach ($documentosVinculados as $key => $d) {
                            if ($d->idDocumento == $documento_id) {
                                $documento_retorno = $d;
                            }
                        }
                    }
                }

                if ($documento_retorno->conteudo == 'Visualização do documento bloqueada enquanto não houver ciência de seus destinatários') {
                    throw new MNIException('Visualização do documento bloqueada enquanto não houver ciência de seus destinatários', 500);
                }

                return NormalizarDocumentoMniService::normalizarConteudo($documento_retorno);
            } elseif ($credencialInformada && ClassificarFalhaMniService::credencialRecusada($retorno->mensagem)) {
                throw new CredencialPjeRecusadaException($retorno->mensagem, 500);
            } elseif (ClassificarFalhaMniService::processoNaoEncontrado($retorno->mensagem)) {
                throw new ProcessoNaoEncontradoNoTribunalException($retorno->mensagem, 500);
            } else {
                throw new MNIException($retorno->mensagem, 500);
            }
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

    /**
     * Consulta unitária usada pelo fallback do download em lote (CT-03).
     *
     * Mesma requisição de execute(), mas devolve o documento cru, sem
     * normalizar o conteúdo nem tratar bloqueio: quem chama classifica e grava.
     * `sucesso = false` sai como ConsultaMNISemSucessoException para que o lote
     * trate como falha só deste documento, e não como falha total.
     *
     * @return object|null documento casado por idDocumento (inclusive vinculado) ou null se não veio
     *
     * @throws ConsultaMNISemSucessoException quando o MNI responde `sucesso = false`
     * @throws MNIException nas demais falhas (transporte, SOAP fault, credenciais)
     */
    public function buscar(
        Tribunal $tribunal,
        string $numeroProcesso,
        string $idDocumento,
        ?string $login = null,
        ?string $senha = null,
    ): ?object {
        try {
            $integracao = $this->novaIntegracao($tribunal);
            $parametros = $this->parametros($tribunal, $numeroProcesso, $idDocumento, $login, $senha);

            $retorno = $integracao->makeSoapRequest('consultarProcesso', $parametros);

            if (!$retorno->sucesso) {
                throw new ConsultaMNISemSucessoException($retorno->mensagem ?? null, 500);
            }

            $documentos = NormalizarDocumentoMniService::indexarPorId(
                NormalizarDocumentoMniService::extrairDocumentos($retorno->processo ?? null)
            );

            return $documentos[$idDocumento] ?? null;
        } catch (MNIException $e) {
            // Sem re-embrulhar: o lote separa ConsultaMNISemSucessoException por instanceof.
            throw $e;
        } catch (DecryptException $e) {
            throw new MNIException('Credenciais MNI/PJ-e tribunal inválidas.', 422);
        } catch (\Exception $e) {
            throw new MNIException($e->getMessage(), 500);
        }
    }

    // Resolvida pelo container para que os testes troquem o cliente SOAP por binding.
    private function novaIntegracao(Tribunal $tribunal): IntegracaoBase
    {
        return app()->makeWith(IntegracaoBase::class, ['url' => $tribunal->url_webservice_mni]);
    }

    private function parametros(Tribunal $tribunal, $numero_processo, $documento_id, $login_pje, $senha_pje): array
    {
        return [
            'idConsultante' => $login_pje ?? $tribunal->login,
            'senhaConsultante' => $senha_pje ?? Crypt::decrypt($tribunal->password),
            'numeroProcesso' => $numero_processo,
            'incluirCabecalho' => false,
            'incluirMovimentos' => false,
            'incluirDocumentos' => false,
            'documento' => $documento_id,
        ];
    }

    // function downloadPdf($inputBase64)
    // {
    //     // Decodificar o base64
    //     $pdfContent = base64_decode($inputBase64);
    //     $pdfOriginal = uniqid(date('HisYmd')) . Str::random(40) . '.pdf';
    //     file_put_contents(storage_path("app/public/downloads/$pdfOriginal"), $pdfContent);
    //     return env('APP_URL') . "/storage/downloads/$pdfOriginal";
    // }
}
