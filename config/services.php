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

    'api' => [
        'token' => env('API_TOKEN'),
    ],

    'samia' => [
        'url' => env('SAMIA_API_URL', 'http://lb-astra-905584199.sa-east-1.elb.amazonaws.com'),
        'api_key' => env('SAMIA_API_KEY', 'dbee3fbb-a09b-48a8-8e22-ce72dd5fd5e8'),
        'base' => env('SAMIA_BASE_KB', 'sim-mni-documentos'),
        'timeout' => env('SAMIA_API_TIMEOUT', 30),
    ],

    'sim_webhook_download' => [
        'url' => rtrim(env('SIM_APP_URL', ''), '/') . '/webhook/download',
        'token' => env('MS_MNI_API_TOKEN'),
        'timeout' => env('SIM_WEBHOOK_TIMEOUT', 10),
    ],

    'sim_app' => [
        'url' => rtrim(env('SIM_APP_URL', ''), '/'),
        'token' => env('MS_MNI_API_TOKEN'),
        'timeout' => env('SIM_WEBHOOK_TIMEOUT', 10),
    ],

    'sim_ocr' => [
        'url'            => env('SIM_OCR_URL'),
        'token'          => env('SIM_OCR_API_TOKEN'),
        'bucket_origem'  => env('SIM_OCR_BUCKET_ORIGEM'),
        'bucket_destino' => env('SIM_OCR_BUCKET_DESTINO'),
        'webhook_url'    => env('SIM_OCR_WEBHOOK_URL'),
        'codigos_documentos_ignorados' => ['4050010', '8030031'],
        // Mimetypes aceitos pelo microservico OCR (extensoes: .bmp .gif .jpeg .jpg .pdf .png .tiff)
        'mimetypes_permitidos' => [
            'application/pdf',
            'text/html',
        ],
    ],
];
