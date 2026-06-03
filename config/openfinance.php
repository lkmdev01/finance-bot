<?php

return [
    'provider' => env('OPENFINANCE_PROVIDER', 'pluggy'),

    'default_sync_days' => (int) env('OPENFINANCE_DEFAULT_SYNC_DAYS', 90),

    'pluggy' => [
        'base_url' => env('PLUGGY_BASE_URL', 'https://api.pluggy.ai'),
        'client_id' => env('PLUGGY_CLIENT_ID'),
        'client_secret' => env('PLUGGY_CLIENT_SECRET'),
        'connect_widget_script' => env('PLUGGY_WIDGET_SCRIPT_URL', 'https://cdn.pluggy.ai/connect/v2/pluggy-connect.js'),
        'include_sandbox' => (bool) env('PLUGGY_INCLUDE_SANDBOX', false),
        'timeout' => (int) env('PLUGGY_TIMEOUT', 30),
    ],
];
