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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
    ],

    'gemini' => [
        'key' => env('GOOGLE_GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash-image'),

        // Limitadores de custo/segurança da edição de fotos — nunca
        // ultrapassados, reservados atomicamente antes de despachar o job
        // (ver EdicaoFotoCotaService). Configuráveis sem alterar código.
        'limite_tentativas_por_foto' => env('GEMINI_LIMITE_TENTATIVAS_POR_FOTO', 2),
        'limite_tentativas_por_imovel' => env('GEMINI_LIMITE_TENTATIVAS_POR_IMOVEL', 10),
        'limite_chamadas_mensal' => env('GEMINI_LIMITE_CHAMADAS_MENSAL', 200),
    ],

    'google_maps' => [
        'key' => env('GOOGLE_MAPS_API_KEY'),
    ],

];
