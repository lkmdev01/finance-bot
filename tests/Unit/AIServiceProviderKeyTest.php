<?php

use App\Services\AIService;

uses(Tests\TestCase::class);

it('injects the configured provider key into the legacy AI service', function () {
    config([
        'ai.provider' => 'groq',
        'ai.api_key' => null,
        'ai.providers.groq.key' => 'groq-provider-key',
    ]);

    app()->forgetInstance(AIService::class);

    $service = app(AIService::class);
    $property = new ReflectionProperty($service, 'apiKey');

    expect($property->getValue($service))->toBe('groq-provider-key');
});
