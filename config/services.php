<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Workflow "Jurídico OCR (Gemini)" no n8n
    |--------------------------------------------------------------------------
    |
    | `url` é a base do workflow (ex.: http://n8n:5678/webhook/juridico-ocr),
    | sem barra no fim; o ms-mni chama `{url}/documents/process`,
    | `{url}/jobs/{job_id}` e `{url}/jobs/{job_id}/chunks` com
    | `Authorization: Bearer {token}`. `base_conhecimento` tem de ser igual ao
    | SIM_OCR_BASE_CONHECIMENTO do Sistema; com outro valor, a busca de lá não
    | acha os trechos. `webhook_base_url` é a base do ms-mni que o n8n alcança
    | para devolver o resultado (em desenvolvimento,
    | http://host.docker.internal:8002).
    |
    | Os prazos vêm da SPEC e têm invariantes, conferidas em
    | tests/Unit/ConfigOcrTest.php:
    | - P, `prazo_sem_progresso_minutos`: o processamento sem progresso
    |   termina em P, abaixo dos 30 min com que o Sistema o dá por falho;
    | - A, `idade_maxima_sem_retorno_minutos`: o documento enviado e sem
    |   retorno só é consultado e reenviado a partir de A. A < P, para que o
    |   reenvio caia numa rodada anterior ao fim por P;
    | - M, `idade_maxima_absoluta_minutos`: o job ainda em andamento com idade
    |   ≥ M é dado por travado e reenviado. A < M. M é também a validade da
    |   credencial do `webhook_url`, que precisa valer pelo menos A;
    | - W, `espera_da_trava_segundos`: a espera do pedido pela trava do
    |   processo, abaixo dos 60 s do cliente do Sistema.
    |
    */

    'n8n_ocr' => [
        'url' => env('N8N_OCR_URL'),
        'token' => env('N8N_OCR_TOKEN'),
        'base_conhecimento' => env('N8N_OCR_BASE_CONHECIMENTO', ''),
        // `?:` e não o padrão de env(): a chave vazia do .env.example também cai no APP_URL.
        'webhook_base_url' => env('N8N_OCR_WEBHOOK_BASE_URL') ?: env('APP_URL'),
        'prazo_sem_progresso_minutos' => 25,
        'idade_maxima_sem_retorno_minutos' => 20,
        'idade_maxima_absoluta_minutos' => 60,
        'espera_da_trava_segundos' => 45,
        'trava_validade_segundos' => 600,
        'timeout_http_segundos' => 30,
    ],
];
