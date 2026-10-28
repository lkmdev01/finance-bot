<?php

use App\Services\SpecializedAiFallbackService;
use App\Models\User;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

it('retries a specialized agent with the configured fallback on rate limit', function () {
    config([
        'ai.specialized_fallback.enabled' => true,
        'ai.specialized_fallback.provider' => 'groq',
        'ai.specialized_fallback.model' => 'openai/gpt-oss-120b',
        'ai.providers.groq.key' => 'test-key',
    ]);

    $response = new StructuredAgentResponse('invocation', ['intent' => 'list_tasks'], 'response', new Usage(10, 5), new Meta());

    $agent = Mockery::mock(Agent::class);
    $agent->shouldReceive('prompt')->once()->with('intent prompt')->andThrow(RateLimitedException::forProvider('groq'));
    $agent->shouldReceive('prompt')->once()->with('intent prompt', [], Lab::Groq, 'openai/gpt-oss-120b')->andReturn($response);

    expect(app(SpecializedAiFallbackService::class)->prompt(User::factory()->create(), $agent, 'intent prompt', 'tasks'))
        ->toBe(['intent' => 'list_tasks']);
});

it('does not retry when the fallback is disabled or has no credential', function (bool $enabled, ?string $key) {
    config([
        'ai.specialized_fallback.enabled' => $enabled,
        'ai.specialized_fallback.provider' => 'groq',
        'ai.specialized_fallback.model' => 'openai/gpt-oss-120b',
        'ai.providers.groq.key' => $key,
    ]);

    $agent = Mockery::mock(Agent::class);
    $agent->shouldReceive('prompt')->once()->with('intent prompt')->andThrow(RateLimitedException::forProvider('groq'));

    expect(fn () => app(SpecializedAiFallbackService::class)->prompt(User::factory()->create(), $agent, 'intent prompt', 'agenda'))
        ->toThrow(RateLimitedException::class);
})->with([
    'disabled' => [false, 'test-key'],
    'missing credential' => [true, null],
]);
