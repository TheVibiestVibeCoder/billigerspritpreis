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

    'econtrol' => [
        'base_url' => env('ECONTROL_API_BASE', 'https://api.e-control.at/api'),
        'fallback_base_url' => env('ECONTROL_API_FALLBACK_BASE', 'https://api.e-control.at/sprit/1.0'),
        'cache_ttl' => (int) env('ECONTROL_CACHE_TTL', 1200),
        'timeout' => (int) env('ECONTROL_HTTP_TIMEOUT', 15),
        'pool_timeout' => (int) env('ECONTROL_POOL_TIMEOUT', 8),
    ],

];
