<?php

namespace App\Services;

use SoapClient;
use DOMDocument;
use Exception;
use Illuminate\Support\Facades\Cache;
use App\Exceptions\MNIExeception;

class SoapClientMNI extends SoapClient
{
    protected $lastRequest;
    protected $cacheTimeout = 3600; // 1 hora de cache

    function __construct($wsdl, $options)
    {
        // Adiciona opções para melhorar performance

        $opts = [
            'http' => [
                'user_agent' => 'PHPSoapClient',
                'timeout' => 1800
            ]
        ];

        $context = stream_context_create($opts);

        $defaultOptions = [
            'trace' => false,
            'keep_alive' => false,
            'stream_context' => $context,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'encoding' => 'UTF-8',
            'compression'   => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP | SOAP_COMPRESSION_DEFLATE,
        ];

        $options = array_merge($defaultOptions, $options);

        parent::__construct($wsdl, $options);
    }

    public function __doRequest($request, $location, $action, $version, $one_way = 0)
    {
        $this->lastRequest = $request;

        // Gera uma chave única para o cache baseado nos parâmetros da requisição
        // $cacheKey = 'soap_request_' . md5($request . $location . $action . $version);

        // // Verifica se existe cache
        // if (Cache::has($cacheKey)) {
        //     return Cache::get($cacheKey);
        // }

        $response = parent::__doRequest($request, $location, $action, $version, $one_way);
        $processedResponse = $this->process($response);

        // Salva no cache
        // Cache::put($cacheKey, $processedResponse, $this->cacheTimeout);

        return $processedResponse;
    }

    protected function process(?string $response): ?string
    {
        if (!$response) {
            return null;
        }


        // Otimiza a regex usando preg_match_all apenas uma vez
        if (!preg_match('/<soap[\s\S]*nvelope>/i', $response, $xml_response)) {
            throw new Exception('No XML has been found.');
        }

        $xml_response = reset($xml_response);

        try {
            $dom = new DOMDocument();
            $dom->loadXML($xml_response, LIBXML_NOCDATA | LIBXML_COMPACT);

            $xop_elements = $dom->getElementsByTagNameNS('http://www.w3.org/2004/08/xop/include', 'Include');

            // Otimiza o processamento dos elementos XOP
            if ($xop_elements->length > 0) {
                $this->processXopElements($dom, $xop_elements, $response);
            }

            return $dom->saveXML();
        } catch (Exception $exception) {
            throw new Exception(sprintf(
                'An error occurred while processing the XML response: %s.',
                $exception->getMessage()
            ));
        }
    }

    protected function processXopElements(DOMDocument $dom, \DOMNodeList $xop_elements, string $response): void
    {
        $counts = $xop_elements->count() - 1;

        for ($i = $counts; $i >= 0; $i--) {
            $xop_element = $xop_elements->item($i);
            if (!($xop_element instanceof \DOMElement)) {
                continue; // Se não é um DOMElement válido, pula
            }

            try {
                $cid = str_replace('cid:', '', $xop_element->getAttribute('href'));

                // Procurar pela parte que contém o conteúdo binário
                $content_id_tag = 'Content-ID: <' . $cid . '>';
                $start = strpos($response, $content_id_tag);

                if ($start === false) {
                    // FALLBACK: Usar método original se não encontrar o Content-ID
                    $this->processXopElementFallback($xop_element, $cid, $response);
                    continue;
                }

                $start += strlen($content_id_tag);

                // Encontrar onde começa o conteúdo binário (após dupla quebra de linha)
                $headerEndPos = strpos($response, "\r\n\r\n", $start);
                if ($headerEndPos === false) {
                    $headerEndPos = strpos($response, "\n\n", $start);
                    if ($headerEndPos !== false) {
                        $contentStart = $headerEndPos + 2; // +2 para pular \n\n
                    } else {
                        // FALLBACK: Usar método original se não encontrar separador
                        $this->processXopElementFallback($xop_element, $cid, $response);
                        continue;
                    }
                } else {
                    $contentStart = $headerEndPos + 4; // +4 para pular \r\n\r\n
                }

                // Encontrar o final do conteúdo (próximo boundary)
                $end = strpos($response, '--uuid:', $contentStart);
                if ($end === false) {
                    // Tentar outras variações de boundary
                    $end = strpos($response, "\r\n--", $contentStart);
                    if ($end === false) {
                        $end = strpos($response, "\n--", $contentStart);
                    }
                }

                if ($end === false) {
                    // Se não encontrar boundary de fechamento, usar até o final
                    $binary = substr($response, $contentStart);
                } else {
                    $binary = substr($response, $contentStart, $end - $contentStart);
                }

                // Remover possíveis quebras de linha extras no final
                $binary = rtrim($binary, "\r\n");

                // Para arquivos binários grandes, não fazer base64_encode do conteúdo já binário
                // O conteúdo binário já está extraído corretamente, apenas definir no nó
                $xop_element->parentNode->nodeValue = base64_encode($binary);
            } catch (\Exception $e) {
                // FALLBACK: Se houver erro, usar método original
                $cid = str_replace('cid:', '', $xop_element->getAttribute('href'));
                $this->processXopElementFallback($xop_element, $cid, $response);
            }
        }
    }

    /**
     * Método de fallback com lógica original para manter compatibilidade
     */
    protected function processXopElementFallback($xop_element, $cid, $response): void
    {
        try {
            $content_id_tag = 'Content-ID: <' . $cid . '>';
            $start = strpos($response, $content_id_tag) + strlen($content_id_tag);
            $end = strpos($response, '--uuid:', $start);

            $binary = substr($response, $start, $end - $start);
            $binary = base64_encode(trim($binary));

            $xop_element->parentNode->nodeValue = $binary;
        } catch (\Exception $e) {
            // Se mesmo o fallback falhar, manter o conteúdo original
            // Isso evita quebrar outras funcionalidades
        }
    }

    public function getLastRequest()
    {
        return $this->lastRequest;
    }
}
