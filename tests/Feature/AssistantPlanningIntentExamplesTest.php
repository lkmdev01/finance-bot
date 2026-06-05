<?php

use App\Assistant\Classifiers\IntentClassifier;
use App\Assistant\DTO\AssistantContextDTO;
use App\Models\User;
use App\Models\WhatsAppContact;

$examples = require __DIR__.'/../Fixtures/assistant_planning_examples.php';

it('classifies planning and recurring examples through the assistant catalog', function (
    string $message,
    string $expectedIntent,
    string $expectedDomain,
    array $state = [],
) {
    $user = User::factory()->create();
    $contact = WhatsAppContact::factory()->create([
        'user_id' => $user->id,
        'phone_number' => '5513991290256',
    ]);

    $parsed = app(IntentClassifier::class)->classify(
        $message,
        new AssistantContextDTO(
            user: $user,
            contact: $contact,
            state: $state,
            timezone: 'America/Sao_Paulo',
            currentMonth: '2026-06',
            currentYear: 2026,
            lastAction: $state['last_action'] ?? null,
            pendingAction: $state['pending_intent'] ?? null,
        ),
    );

    expect($parsed->intent->value)->toBe($expectedIntent)
        ->and($parsed->domain)->toBe($expectedDomain);
})->with(array_map(
    fn (array $example) => [
        $example['message'],
        $example['expected_intent'],
        $example['expected_domain'],
        $example['state'] ?? [],
    ],
    $examples,
));

it('exposes missing amount for recurring transactions through assistant metadata', function () {
    $user = User::factory()->create();
    $contact = WhatsAppContact::factory()->create([
        'user_id' => $user->id,
        'phone_number' => '5513991290256',
    ]);

    $parsed = app(IntentClassifier::class)->classify(
        'todo dia 5 pago academia',
        new AssistantContextDTO(
            user: $user,
            contact: $contact,
            state: [],
            timezone: 'America/Sao_Paulo',
            currentMonth: '2026-06',
            currentYear: 2026,
            lastAction: null,
            pendingAction: null,
        ),
    );

    expect($parsed->intent->value)->toBe('create_recurring_transaction')
        ->and($parsed->missingFields)->toContain('amount');
});

it('exposes active missing fields for savings goals and subscriptions', function () {
    $user = User::factory()->create();
    $contact = WhatsAppContact::factory()->create([
        'user_id' => $user->id,
        'phone_number' => '5513991290256',
    ]);

    $context = new AssistantContextDTO(
        user: $user,
        contact: $contact,
        state: [],
        timezone: 'America/Sao_Paulo',
        currentMonth: '2026-06',
        currentYear: 2026,
        lastAction: null,
        pendingAction: null,
    );

    $classifier = app(IntentClassifier::class);

    $goal = $classifier->classify('criar meta viagem', $context);
    $subscription = $classifier->classify('criar assinatura Netflix mensal', $context);

    expect($goal->intent->value)->toBe('create_goal')
        ->and($goal->missingFields)->toContain('target_amount')
        ->and($subscription->intent->value)->toBe('create_subscription')
        ->and($subscription->missingFields)->toContain('amount')
        ->and($subscription->missingFields)->toContain('due_day');
});
