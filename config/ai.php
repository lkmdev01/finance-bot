<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Legacy / app-specific AI settings
    |--------------------------------------------------------------------------
    */
    'use_sdk' => env('AI_USE_SDK', true),
    'provider' => env('AI_PROVIDER', 'groq'), // 'gemini', 'ollama', 'groq', 'openai'
    'api_key' => env('AI_API_KEY'),
    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        'model' => env('OLLAMA_MODEL', 'llama3.2'),
    ],
    'groq' => [
        'model' => env('GROQ_MODEL', 'openai/gpt-oss-20b'),
    ],
    'openai' => [
        'model' => env('OPENAI_MODEL', 'gpt-3.5-turbo'),
    ],
    'specialized_fallback' => [
        'enabled' => env('AI_SPECIALIZED_FALLBACK_ENABLED', true),
        'provider' => env('AI_SPECIALIZED_FALLBACK_PROVIDER', 'groq'),
        'model' => env('AI_SPECIALIZED_FALLBACK_MODEL', env('GROQ_MODEL_SMARTEST', 'openai/gpt-oss-120b')),
    ],
    'user_monthly_token_limit' => (int) env('AI_USER_MONTHLY_TOKEN_LIMIT', 20000),
    'user_monthly_token_exempt_ids' => array_values(array_unique(array_filter(
        array_map('intval', explode(',', (string) env('AI_USER_MONTHLY_TOKEN_EXEMPT_IDS', ''))),
        static fn (int $id): bool => $id > 0,
    ))),
    'drive_metadata' => [
        'provider' => env('DRIVE_AI_PROVIDER', 'none'),
        'api_key' => env('DRIVE_AI_API_KEY', env('AI_API_KEY')),
        'vision_model' => env('DRIVE_AI_VISION_MODEL', 'qwen/qwen3.6-27b'),
        'metadata_model' => env('DRIVE_AI_METADATA_MODEL', env('GROQ_MODEL', 'openai/gpt-oss-20b')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Laravel AI SDK defaults
    |--------------------------------------------------------------------------
    */
    'default' => env('AI_PROVIDER', 'groq'),
    'default_for_images' => 'gemini',
    'default_for_audio' => 'openai',
    'default_for_transcription' => 'openai',
    'default_for_embeddings' => 'openai',
    'default_for_reranking' => 'cohere',

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
        ],
    ],

    'providers' => [
        'groq' => [
            'driver' => 'groq',
            'key' => env('GROQ_API_KEY', env('AI_API_KEY')),
            'models' => [
                'text' => [
                    'default' => env('GROQ_MODEL', 'openai/gpt-oss-20b'),
                    'cheapest' => env('GROQ_MODEL_CHEAPEST', 'openai/gpt-oss-20b'),
                    'smartest' => env('GROQ_MODEL_SMARTEST', 'openai/gpt-oss-120b'),
                ],
            ],
        ],
        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
        ],
        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/'),
        ],
        'ollama' => [
            'driver' => 'ollama',
            'key' => env('OLLAMA_API_KEY', ''),
            'url' => env('OLLAMA_URL', env('OLLAMA_BASE_URL', 'http://localhost:11434')),
        ],
    ],
];
