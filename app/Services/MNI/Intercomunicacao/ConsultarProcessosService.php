<?php

namespace App\Services\MNI\Intercomunicacao;

use App\Exceptions\ConsultaMNISemSucessoException;
use App\Exceptions\MNIException;
use App\Models\Tribunal;
use App\Services\IntegracaoBase;
use App\Services\MNI\NormalizarDocumentoMniService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Operação MNI `consultarProcessos` (plural), usada pelo download em lote
 * para trazer vários documentos de um processo, com conteúdo, numa chamada
 * por página (CT-01).
 *
 * Usa `url_webservice_mni`, a mesma URL da consulta unitária de documento,
 * e não `url_webservice_mni_consultar_processo`. Não persiste nada.
 */
class ConsultarProcessosService
{
    public const OPERACAO = 'consultarProcessos';

    /**
     * Nem todo tribunal expõe a operação. Verificar antes evita invocar uma
     * operação inexistente, que vira SoapFault e seria tratada como falha total.
     *
     * @throws MNIException quando o WSDL não pode ser carregado
     */
    public function operacaoDisponivel(Tribunal $tribunal): bool
    {
        try {
            return $this->novaIntegracao($tribunal)->possuiOperacao(self::OPERACAO);
        } catch (MNIException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new MNIException($e->getMessage(), 500);
        }
    }

    /**
     * Lê uma página da consulta: mesma lista de ids em todas as páginas,
     * `pagina` a partir de 1 e `tamanhoPagina` decidido por quem chama.
     *
     * @param  array<int, int|string>  $idsDocumentos
     * @return array<string, object> documentos crus por `(string) idDocumento`, inclusive vinculados;
     *                               procure com `(string) $id`, já que o PHP converte chave decimal em int
     *
     * @throws ConsultaMNISemSucessoException quando o MNI responde `sucesso = false`
     * @throws MNIException nas demais falhas (transporte, SOAP fault, credenciais)
     */
    public function consultarPagina(
        Tribunal $tribunal,
        string $numeroProcesso,
        array $idsDocumentos,
        int $pagina,
        int $tamanhoPagina,
        ?string $login = null,
        ?string $senha = null,
    ): array {
        try {
            $parametros = [
                'idConsultante' => $login ?? $tribunal->login,
                'senhaConsultante' => $senha ?? Crypt::decrypt($tribunal->password),
                'numeroProcesso' => $numeroProcesso,
                // Em consultarProcessos o nome é `movimentos`, não `incluirMovimentos`.
                'movimentos' => false,
                'incluirCabecalho' => false,
                // Os documentos filtrados por `documento` vêm com conteúdo mesmo com false.
                'incluirDocumentos' => false,
                'documento' => array_values(array_map('strval', $idsDocumentos)),
                'pagina' => $pagina,
                'tamanhoPagina' => $tamanhoPagina,
            ];

            $retorno = $this->novaIntegracao($tribunal)->makeSoapRequest(self::OPERACAO, $parametros);

            if (!$retorno->sucesso) {
                throw new ConsultaMNISemSucessoException($retorno->mensagem ?? null, 500);
            }

            return NormalizarDocumentoMniService::indexarPorId(
                NormalizarDocumentoMniService::extrairDocumentos($retorno->processos ?? null)
            );
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
}
