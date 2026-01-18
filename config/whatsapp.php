<?php

return [
    'baileys' => [
        'base_url' => env('BAILEYS_SERVICE_URL', 'http://localhost:3001'),
        'webhook_secret' => env('BAILEYS_WEBHOOK_SECRET', 'your-secret-key'),
    ],
    'evolution_api' => [
        'base_url' => env('EVOLUTION_API_BASE_URL', 'http://localhost:8080'),
        'api_key' => env('EVOLUTION_API_KEY'),
        'instance_name' => env('EVOLUTION_API_INSTANCE_NAME', 'default'),
    ],
];
