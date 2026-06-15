<?php

return [
    'provider' => env('AI_PROVIDER', 'groq'), // 'gemini', 'ollama', 'groq', 'openai'
    'api_key' => env('AI_API_KEY'),
    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        'model' => env('OLLAMA_MODEL', 'llama3.2'),
    ],
    'groq' => [
        'model' => env('GROQ_MODEL', 'llama-3.1-8b-instant'),
    ],
    'openai' => [
        'model' => env('OPENAI_MODEL', 'gpt-3.5-turbo'),
    ],
    'drive_metadata' => [
        'provider' => env('DRIVE_AI_PROVIDER', 'none'),
        'api_key' => env('DRIVE_AI_API_KEY'),
        'vision_model' => env('DRIVE_AI_VISION_MODEL', 'gpt-4o-mini'),
        'metadata_model' => env('DRIVE_AI_METADATA_MODEL', 'gpt-4o-mini'),
    ],
];
