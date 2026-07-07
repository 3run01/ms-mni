<?php

namespace App\Services\Processo;

use App\Models\ProcessoParte;
use App\Models\ProcessoParteRepresentante;
use App\Models\ProcessoPrioridade;

class SalvarParteProcessoService
{

    public function execute($processo, $partes)
    {
        $partes = is_array($partes) ? $partes : [$partes];
        foreach ($partes as $parte) {
            if (is_array($parte->parte)) {
                foreach ($parte->parte as $polo) {
                    $this->salvarParte($processo, $polo, $parte->polo);
                }
            } else {
                $this->salvarParte($processo, $parte->parte, $parte->polo);
            }
        }
    }

    public function salvarParte($processo, $parte, $polo)
    {
        $updateFields = [
            'processo_id' => $processo->id,
            'nome' => $parte->pessoa->nome,
        ];

        if (!empty($parte->pessoa->numeroDocumentoPrincipal)) {
            $updateFields['cpf_cnpj'] = $parte->pessoa->numeroDocumentoPrincipal;
        }

        $p = ProcessoParte::updateOrCreate(
            $updateFields,
            [
                'nome' => $parte->pessoa->nome,
                'cpf_cnpj' => $parte->pessoa->numeroDocumentoPrincipal ?? null,
                'polo' => $polo,
                'cep' => !empty($parte->pessoa->endereco->cep) ? $parte->pessoa->endereco->cep : null,
                'logradouro' => !empty($parte->pessoa->endereco->logradouro) ? $parte->pessoa->endereco->logradouro : null,
                'numero' => !empty($parte->pessoa->endereco->numero) ? $parte->pessoa->endereco->numero : null,
                'bairro' => !empty($parte->pessoa->endereco->bairro) ? $parte->pessoa->endereco->bairro : null,
                'municipio' => !empty($parte->pessoa->endereco->cidade) ? $parte->pessoa->endereco->cidade : null,
                'estado' => !empty($parte->pessoa->endereco->estado) ? $parte->pessoa->endereco->estado : null,
            ]
        );

        if (!empty($parte->advogado)) {
            $this->salvarRepresentanteProcessual($processo, $parte->advogado, $p);
        }
    }

    public function salvarRepresentanteProcessual($processo, $representante, $parte)
    {
        // if (!$representante->numeroDocumentoPrincipal) {
        //     dd('não possui documento principal: ' . $representante);
        // }

        $representante = is_array($representante) ? $representante : [$representante];
        // dd($representante->numeroDocumentoPrincipal);
        foreach ($representante as $r) {
            ProcessoParteRepresentante::updateOrCreate(
                [
                    'processo_id' => $processo->id,
                    'parte_id' => $parte->id,
                    'numero_documento_principal' => $r->numeroDocumentoPrincipal ?? null,
                ],
                [
                    'nome' => $r->nome,
                    'inscricao' => !empty($r->inscricao) ? $r->inscricao : null,
                    'tipo_representante' => $r->tipoRepresentante,
                ]
            );
        }
    }
}
