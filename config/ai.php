<?php

return [
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
    'drive_metadata' => [
        'provider' => env('DRIVE_AI_PROVIDER', 'none'),
        'api_key' => env('DRIVE_AI_API_KEY', env('AI_API_KEY')),
        'vision_model' => env('DRIVE_AI_VISION_MODEL', 'qwen/qwen3.6-27b'),
        'metadata_model' => env('DRIVE_AI_METADATA_MODEL', env('GROQ_MODEL', 'openai/gpt-oss-20b')),
    ],

    // Configuração para laravel/ai SDK
    'providers' => [
        'groq' => [
            'driver' => 'openai-compatible',
            'key' => env('GROQ_API_KEY'),
            'url' => 'https://api.groq.com/openai/v1',
        ],
        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
        ],
        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
        ],
    ],
    'models' => [
        'text' => env('AI_TEXT_MODEL', 'groq:llama-3.3-70b-versatile'),
    ],
];
