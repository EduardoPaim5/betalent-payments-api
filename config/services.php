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
            'base_url' => env('GATEWAY_1_BASE_URL', 'http://localhost:3001'),
            'email' => env('GATEWAY_1_EMAIL', 'dev@betalent.tech'),
            'token' => env('GATEWAY_1_TOKEN', 'FEC9BB078BF338F464F96B48089EB498'),
        ],
        'gateway_2' => [
            'base_url' => env('GATEWAY_2_BASE_URL', 'http://localhost:3002'),
            'auth_token' => env('GATEWAY_2_AUTH_TOKEN', 'tk_f2198cc671b5289fa856'),
            'auth_secret' => env('GATEWAY_2_AUTH_SECRET', '3d15e8ed6131446ea7e3456728b1211f'),
        ],
    ],
];
