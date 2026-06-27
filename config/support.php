<?php

return [
    'email' => env('SUPPORT_EMAIL', env('MAIL_FROM_ADDRESS', 'suporte@inovaforce.com.br')),
    'whatsapp_number' => env('SUPPORT_WHATSAPP_NUMBER'),
    'whatsapp_url' => env('SUPPORT_WHATSAPP_URL'),
    'response_time' => env('SUPPORT_RESPONSE_TIME', 'respondemos em ate 1 dia util'),
];
