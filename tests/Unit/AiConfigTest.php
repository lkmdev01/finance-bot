<?php

uses(Tests\TestCase::class);

use App\Ai\FinancialAgent;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Enums\Lab;

it('configures the AI SDK to use groq by default', function () {
    expect(config('ai.default'))->toBe('groq')
        ->and(config('ai.providers.groq.driver'))->toBe('groq')
        ->and(config('ai.providers.groq.models.text.default'))->not->toBeEmpty();
});

it('pins FinancialAgent to the Groq provider', function () {
    $attributes = (new ReflectionClass(FinancialAgent::class))->getAttributes(Provider::class);

    expect($attributes)->toHaveCount(1)
        ->and($attributes[0]->newInstance()->value)->toBe(Lab::Groq);
});
