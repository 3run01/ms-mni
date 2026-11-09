<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Credenciais PJe padrão
    |--------------------------------------------------------------------------
    |
    | Par usado quando a requisição não envia login_pje/senha_pje. Se estiver
    | vazio, a requisição segue sem credencial e o fallback das credenciais
    | cadastradas no tribunal (camada de service) decide.
    |
    */

    'credenciais_padrao' => [
        'login' => env('PJE_LOGIN_PADRAO'),
        'senha' => env('PJE_SENHA_PADRAO'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoramento periódico de processos
    |--------------------------------------------------------------------------
    |
    | Limites do recurso de monitoramento via API. O despacho roda a cada
    | 30 minutos e enfileira os vencidos na fila serial `monitoramento`.
    |
    */

    'monitoramento' => [
        'max_ativos_por_token' => env('PJE_MONITORAMENTO_MAX_ATIVOS_POR_TOKEN', 500),
        'intervalo_min_horas' => 1,
        'intervalo_max_horas' => 720,
        'max_falhas_consecutivas' => 5,
        'limite_itens_payload' => 500,
        'bloqueio_despacho_minutos' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Download de documentos em lote
    |--------------------------------------------------------------------------
    |
    | Um job baixa até `tamanho_lote` documentos do mesmo processo com uma
    | chamada `consultarProcessos` por página, em vez de uma chamada por
    | documento — é isso que tira a carga do MNI do tribunal. Reduzir o lote
    | é a saída sem deploy quando o job estoura memória ou timeout do
    | supervisor `mni-download` (lotes com áudio são o caso conhecido).
    |
    | `max_paginas` é o teto de páginas lidas por execução. Vazio, vale
    | ceil(tamanho_lote / tamanhoPagina) + 1; a página extra cobre o
    | tribunal que pagina por processo e não por documento. Valores
    | inválidos voltam ao padrão em ParametrosDownloadEmLote.
    |
    */

    'download_documentos' => [
        'tamanho_lote' => env('PJE_DOWNLOAD_TAMANHO_LOTE', 20),
        'max_paginas' => env('PJE_DOWNLOAD_MAX_PAGINAS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Atualização do processo em segundo plano (DEP-08)
    |--------------------------------------------------------------------------
    |
    | `janela_segundos`: enquanto a janela de um (tribunal_id, numero_processo)
    | está aberta, GET /processo/visualizar com o processo no banco não
    | enfileira de novo o BaixarProcessoMNIJob, seja qual for o par da
    | chamada. Vale só para /processo/visualizar: /processo/consultar e o
    | despacho a partir do expediente continuam com 1 job por chamada.
    | A janela fica no store de cache padrão, e o `cache:clear` de cada deploy
    | (build.sh) apaga as abertas: a 1ª chamada depois do deploy enfileira um
    | job (LIM-04). Sem variável de ambiente, como os outros limites fixos.
    |
    */

    'atualizacao_processo' => [
        'janela_segundos' => 600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Callbacks de saída
    |--------------------------------------------------------------------------
    |
    | `hosts_liberados` (CALLBACK_HOSTS_LIBERADOS, separados por vírgula):
    | fora de `production`, o CallbackUrlValidator aceita `http` ou `https`
    | para esses hosts, mesmo que resolvam para IP interno. É o que deixa o
    | Sistema de desenvolvimento receber avisos em `http://host.docker.internal`.
    | Em `production` a lista é ignorada. Vazia por padrão.
    |
    */

    'callback' => [
        'hosts_liberados' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('CALLBACK_HOSTS_LIBERADOS', ''))),
            static fn (string $host) => $host !== '',
        )),
    ],

];
