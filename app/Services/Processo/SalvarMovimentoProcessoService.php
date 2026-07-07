<?php

namespace App\Services\Processo;

use App\Models\ProcessoMovimento;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SalvarMovimentoProcessoService
{
    public function execute($processo, $movimentos)
    {
        $movimentos = is_array($movimentos) ? $movimentos : [$movimentos];

        DB::transaction(function () use ($processo, $movimentos) {
            foreach ($movimentos as $movimento) {
                if (empty($movimento->identificadorMovimento)) {
                    continue;
                }

                $fonte = !empty($movimento->movimentoNacional)
                    ? $movimento->movimentoNacional
                    : (!empty($movimento->movimentoLocal) ? $movimento->movimentoLocal : null);

                if ($fonte === null) {
                    continue;
                }

                $codigoNacional = property_exists($fonte, 'codigoNacional')
                    ? $fonte->codigoNacional
                    : null;
                $complemento = property_exists($fonte, 'complemento')
                    ? $fonte->complemento
                    : null;

                ProcessoMovimento::updateOrCreate(
                    [
                        'processo_id' => $processo->id,
                        'identificador_movimento' => $movimento->identificadorMovimento,
                    ],
                    [
                        'codigo_nacional' => $codigoNacional,
                        'complemento' => $complemento,
                        'data_hora' => Carbon::parse($movimento->dataHora)->format('Y-m-d H:i:s'),
                        'id_documento_vinculado' => !empty($movimento->idDocumentoVinculado) && !is_array($movimento->idDocumentoVinculado)
                            ? $movimento->idDocumentoVinculado
                            : null,
                    ]
                );
            }
        });
    }
}
