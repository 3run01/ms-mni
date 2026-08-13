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

];
