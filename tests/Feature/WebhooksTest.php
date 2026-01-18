<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Webhook;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('can access webhooks index page', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->get(route('webhooks.index'))
        ->assertSuccessful();
});

it('can create a webhook', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->get(route('webhooks.create'))
        ->assertSuccessful();
});

it('can check if webhook should trigger for event', function () {
    $webhook = Webhook::factory()->create([
        'events' => ['transaction.created', 'budget.exceeded'],
        'is_active' => true,
    ]);

    expect($webhook->shouldTrigger('transaction.created'))->toBeTrue();
    expect($webhook->shouldTrigger('budget.exceeded'))->toBeTrue();
    expect($webhook->shouldTrigger('transaction.updated'))->toBeFalse();
});

it('does not trigger inactive webhooks', function () {
    $webhook = Webhook::factory()->create([
        'events' => ['transaction.created'],
        'is_active' => false,
    ]);

    expect($webhook->shouldTrigger('transaction.created'))->toBeFalse();
});

it('records success and failure counts', function () {
    $webhook = Webhook::factory()->create([
        'success_count' => 0,
        'failure_count' => 0,
    ]);

    $webhook->recordSuccess();
    expect($webhook->fresh()->success_count)->toBe(1);

    $webhook->recordFailure();
    expect($webhook->fresh()->failure_count)->toBe(1);
});
