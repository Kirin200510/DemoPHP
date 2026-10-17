<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'ai_engine' => [
        'url' => env('AI_ENGINE_URL', 'http://127.0.0.1:8000'),
        'default_ocr_provider' => env('OCR_PROVIDER', 'local_hybrid'),
        'ocr_provider_state_path' => env('AI_OCR_PROVIDER_STATE_PATH', storage_path('app/private/ocr-provider.json')),
        'connect_timeout' => env('AI_ENGINE_CONNECT_TIMEOUT', 5),
        'timeout' => env('AI_ENGINE_TIMEOUT', 360),
        'batch_timeout' => env('AI_ENGINE_BATCH_TIMEOUT', 600),
        'retry_attempts' => env('AI_ENGINE_RETRY_ATTEMPTS', 3),
        'retry_delay_milliseconds' => env('AI_ENGINE_RETRY_DELAY_MILLISECONDS', 500),
        'alert_window_minutes' => env('AI_ENGINE_ALERT_WINDOW_MINUTES', 15),
        'alert_minimum_requests' => env('AI_ENGINE_ALERT_MINIMUM_REQUESTS', 5),
        'alert_failure_ratio' => env('AI_ENGINE_ALERT_FAILURE_RATIO', 0.4),
        'alert_cooldown_minutes' => env('AI_ENGINE_ALERT_COOLDOWN_MINUTES', 15),
    ],

];
