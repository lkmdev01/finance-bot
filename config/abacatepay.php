<?php

return [
    'base_url' => env('ABACATEPAY_BASE_URL', 'https://api.abacatepay.com/v2'),
    'api_key' => env('ABACATEPAY_API_KEY'),
    'webhook_secret' => env('ABACATEPAY_WEBHOOK_SECRET'),
    'public_hmac_key' => env('ABACATEPAY_PUBLIC_HMAC_KEY'),
    'timeout' => (int) env('ABACATEPAY_TIMEOUT', 15),
];
