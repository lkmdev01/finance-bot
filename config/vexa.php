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
    'worker_secret' => env('VEXA_TRANSCRIPTION_WORKER_SECRET'),
    'worker_signature_tolerance_seconds' => (int) env('VEXA_TRANSCRIPTION_SIGNATURE_TOLERANCE', 300),
    'transcription_lease_seconds' => (int) env('VEXA_TRANSCRIPTION_LEASE_SECONDS', 600),
    'transcription_max_attempts' => (int) env('VEXA_TRANSCRIPTION_MAX_ATTEMPTS', 5),
    'transcription_retry_backoff_seconds' => [60, 300, 900, 1800],
    'transcription_min_words' => (int) env('VEXA_TRANSCRIPTION_MIN_WORDS', 40),
    'transcription_min_speech_seconds' => (int) env('VEXA_TRANSCRIPTION_MIN_SPEECH_SECONDS', 30),
    'retention_days' => (int) env('VEXA_RETENTION_DAYS', 90),
];
