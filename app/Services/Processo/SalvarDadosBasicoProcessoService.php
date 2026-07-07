<?php

namespace App\Services\Processo;

use App\Models\Processo;
use App\Models\ProcessoPrioridade;
use App\Models\ProcessoAssunto;
use App\Models\AssuntoCNJ;
use Illuminate\Support\Facades\DB;

class SalvarDadosBasicoProcessoService
{
    public function execute($tribunal, $dadosBasicos)
    {
        $assuntos = [];
        if (!empty($dadosBasicos->assunto)) {
            if (is_array($dadosBasicos->assunto)) {
                foreach ($dadosBasicos->assunto as $assunto) {
                    if (!empty($assunto) && !empty($assunto->codigoNacional)) {
                        $assuntos[] = $assunto;
                    }
                }
            } elseif (!empty($dadosBasicos->assunto->codigoNacional)) {
                $assuntos[] = $dadosBasicos->assunto;
            }
        }
        $processo = $this->salvar($tribunal, $dadosBasicos);


        if (!empty($dadosBasicos->prioridade)) {
            $prioridades = is_array($dadosBasicos->prioridade) ? $dadosBasicos->prioridade : [$dadosBasicos->prioridade];
            if (!empty($prioridades)) {
                $this->salvarPrioridades($processo, $prioridades);
            }
        }

        if (!empty($assuntos)) {
            $this->salvarAssunto($processo, $assuntos);
        }


        return $processo;
        // DB::commit();
    }

    public function salvar($tribunal, $dadosBasicos)
    {
        // Determinar o status do processo com base nos dados do MNI
        $statusProcesso = $this->determinarStatusProcesso($tribunal, $dadosBasicos);

        $assuntoPrincipal = null;
        if (isset($dadosBasicos->assunto)) {
            $assuntoPrincipal = is_array($dadosBasicos->assunto) ? $dadosBasicos->assunto[0] : $dadosBasicos->assunto;
        }

        // Tratar o valor da causa
        $valorCausa = 0;
        if (!empty($dadosBasicos->valorCausa)) {
            $valorCausa = (float) $dadosBasicos->valorCausa;
        }

        $processo = Processo::updateOrCreate(
            [
                'tribunal_id' => $tribunal->id,
                'numero_processo' => $dadosBasicos->numero,
            ],
            [
                'jurisdicao_codigo' => $dadosBasicos->codigoLocalidade,
                'classe_codigo' => $dadosBasicos->classeProcessual,
                'assunto_codigo' => !empty($assuntoPrincipal) ? $assuntoPrincipal->codigoNacional : null,
                // 'competencia_codigo' => !empty($dadosBasicos),
                // 'prioridade',
                'valor_causa' => $valorCausa,
                'nivel_sigilo' => $dadosBasicos->nivelSigilo,
                'status' => $statusProcesso,
                'nome_orgao_julgador' => !empty($dadosBasicos->orgaoJulgador) ? $dadosBasicos->orgaoJulgador->nomeOrgao : null,
                'codigo_orgao_julgador' => !empty($dadosBasicos->orgaoJulgador) ? $dadosBasicos->orgaoJulgador->codigoOrgao : null,
                'instancia_orgao_julgador' => !empty($dadosBasicos->orgaoJulgador) ? $dadosBasicos->orgaoJulgador->instancia : null,
                // 'justica_gratuita',
                // 'pedido_liminar',
                // 'motivo_segredo_justica',
                // 'tentantivas_envio',
                // 'payload_envio',
                // 'tipo_eleicao'
            ]
        );

        return $processo;
    }

    /**
     * Determina o status do processo com base nos parâmetros recebidos
     *
     * @param object $tribunal
     * @param object $dadosBasicos
     * @return string
     */
    private function determinarStatusProcesso($tribunal, $dadosBasicos): string
    {
        $statusProcesso = Processo::STATUS_PETICIONADO; // status padrão
        $statusMNI = null;

        // Verificar o status retornado pelo MNI
        if (!empty($dadosBasicos->outroParametro) && is_array($dadosBasicos->outroParametro)) {
            $primeiroParametro = $dadosBasicos->outroParametro[0] ?? null;
            if (
                $primeiroParametro &&
                isset($primeiroParametro->nome) &&
                isset($primeiroParametro->valor) &&
                $primeiroParametro->nome === 'mni:situacaoProcesso'
            ) {
                $statusMNI = $primeiroParametro->valor;
                if ($statusMNI === 'Arquivado') {
                    $statusProcesso = Processo::STATUS_ARQUIVADO;
                }
            }
        }

        // Se o processo existe e está arquivado, mas o MNI retornou status diferente de Arquivado
        $processoExistente = Processo::where('tribunal_id', $tribunal->id)
            ->where('numero_processo', $dadosBasicos->numero)
            ->first();

        if ($processoExistente && $processoExistente->status === Processo::STATUS_ARQUIVADO) {
            if ($statusMNI !== 'Arquivado') {
                $statusProcesso = Processo::STATUS_PETICIONADO;
            }
        }

        return $statusProcesso;
    }

    public function salvarPrioridades($processo, $prioridades): void
    {
        foreach ($prioridades as $prioridade) {
            ProcessoPrioridade::updateOrCreate(
                [
                    'processo_id' => $processo->id,
                    'descricao' => $prioridade
                ],
                [
                    'descricao' => $prioridade
                ]
            );
        }
    }

    public function salvarAssunto($processo, $assuntos): void
    {
        foreach ($assuntos as $assunto) {
            if (empty($assunto) || empty($assunto->codigoNacional)) {
                continue; // pula assuntos inválidos
            }
            ProcessoAssunto::updateOrCreate(
                [
                    'processo_id' => $processo->id,
                    'assunto_codigo' => $assunto->codigoNacional,
                ],
                [
                    'nome' => !empty(AssuntoCNJ::where('codigo', $assunto->codigoNacional)->first()->descricao) ? AssuntoCNJ::where('codigo', $assunto->codigoNacional)->first()->descricao : 'Não disponível',
                    'principal' => $assunto->principal
                ]
            );
        }
    }
}
