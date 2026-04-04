<?php

return [
    'tutorial' => [
        'contact_number' => env('WHATSAPP_CONTACT_NUMBER', '+55 13 97605-4715'),
        'contact_label' => env('WHATSAPP_CONTACT_LABEL', 'WhatsApp oficial do InovaFinance'),
        'prefilled_message' => env('WHATSAPP_TUTORIAL_MESSAGE', 'Oi! Acabei de entrar no InovaFinance e quero testar o robô.'),
    ],
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

