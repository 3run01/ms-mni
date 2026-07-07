<?php

namespace App\Services\MNI\Intercomunicacao;

use App\Exceptions\MNIException;
use App\Models\Tribunal;
use App\Services\IntegracaoBase;
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
        try {
            $integracao = new IntegracaoBase($tribunal->url_webservice_mni);
            $parametros = [
                'idConsultante' => $login_pje ?? $tribunal->login,
                'senhaConsultante' => $senha_pje ?? Crypt::decrypt($tribunal->password),
                'numeroProcesso' => $numero_processo,
                'incluirCabecalho' => false,
                'incluirMovimentos' => false,
                'incluirDocumentos' => false,
                'documento' => $documento_id,
            ];



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

                if ($documento_retorno->mimetype == 'application/pdf') {
                    $documento_retorno->conteudo = base64_encode($documento_retorno->conteudo);
                } else if (in_array($documento_retorno->mimetype, ['video/mp4', 'video/quicktime', 'audio/mpeg', 'audio/mp3'])) {
                    $isBase64 = false;
                    if (is_string($documento_retorno->conteudo)) {
                        $decoded = base64_decode($documento_retorno->conteudo, true);
                        if ($decoded !== false && base64_encode($decoded) === $documento_retorno->conteudo) {
                            $isBase64 = true;
                        }
                    }

                    if (!$isBase64) {
                        $documento_retorno->conteudo = base64_encode($documento_retorno->conteudo);
                    }
                } else {
                    $documento_retorno->conteudo = base64_encode($documento_retorno->conteudo);
                }

                return $documento_retorno;
            } else {
                throw new MNIException($retorno->mensagem, 500);
            }
        } catch (MNIException $e) {
            throw new MNIException($e->getError(), 500);
        } catch (\Exception $e) {
            throw new MNIException($e->getMessage(), 500);
        }
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
