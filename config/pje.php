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

];
