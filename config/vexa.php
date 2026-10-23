<?php

return [
    'enabled' => (bool) env('VEXA_ENABLED', false),
    'base_url' => env('VEXA_BASE_URL'),
    'api_key' => env('VEXA_API_KEY'),
    'webhook_secret' => env('VEXA_WEBHOOK_SECRET'),
    'webhook_url' => env('VEXA_WEBHOOK_URL'),
    'bot_name' => env('VEXA_BOT_NAME', 'InovaFinance'),
    'language' => env('VEXA_LANGUAGE'),
    'poll_seconds' => (int) env('VEXA_POLL_SECONDS', 60),
    'webhook_tolerance_seconds' => (int) env('VEXA_WEBHOOK_TOLERANCE_SECONDS', 300),
];
