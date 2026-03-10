<?php

return [
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

    'gateways' => [
        'gateway_1' => [
            'base_url' => env('GATEWAY_1_BASE_URL'),
            'email' => env('GATEWAY_1_EMAIL'),
            'token' => env('GATEWAY_1_TOKEN'),
        ],
        'gateway_2' => [
            'base_url' => env('GATEWAY_2_BASE_URL'),
            'auth_token' => env('GATEWAY_2_AUTH_TOKEN'),
            'auth_secret' => env('GATEWAY_2_AUTH_SECRET'),
        ],
    ],
];
