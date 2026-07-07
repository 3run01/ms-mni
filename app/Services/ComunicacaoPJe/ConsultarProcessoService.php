<?php

namespace App\Services\ComunicacaoPJe;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ConsultarProcessoService
{
    public function execute($numero_processo = null, $tribunal_sigla = null, $nome_parte = null, $data_disponibilizacao_inicio = null, $data_disponibilizacao_fim = null, $pagina = 1)
    {
        $url = 'https://comunicaapi.pje.jus.br/api/v1/comunicacao';

        $params = array_filter([
            'numeroProcesso' => preg_replace('/[^0-9]/', '', $numero_processo),
            'siglaTribunal' => strtoupper($tribunal_sigla),
            'nomeParte' => mb_strtoupper($nome_parte),
            'dataDisponibilizacaoInicio' => $data_disponibilizacao_inicio,
            'dataDisponibilizacaoFim' => $data_disponibilizacao_fim,
            'pagina' => $pagina,
        ]);


        $response = Http::withoutVerifying()->get($url, $params);

        return $response->json();
    }
}
