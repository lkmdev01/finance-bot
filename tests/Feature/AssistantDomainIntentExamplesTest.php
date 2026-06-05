<?php

use App\Assistant\Classifiers\IntentClassifier;
use App\Assistant\Context\AssistantContextBuilder;
use App\Models\User;
use App\Models\WhatsAppContact;

$examples = require __DIR__.'/../Fixtures/assistant_domain_examples.php';

it('classifies budget notes reminders and drive intents through the assistant catalog', function (
    string $message,
    string $expectedIntent,
    string $expectedDomain,
    ?float $expectedAmount = null,
) {
    $user = User::factory()->create();
    $contact = WhatsAppContact::factory()->create([
        'user_id' => $user->id,
        'phone_number' => '5513991290256',
    ]);

    $context = app(AssistantContextBuilder::class)->build($user, $contact);
    $parsed = app(IntentClassifier::class)->classify($message, $context);

    expect($parsed->intent->value)->toBe($expectedIntent)
        ->and($parsed->domain)->toBe($expectedDomain);

    if ($expectedAmount !== null) {
        expect((float) ($parsed->data['amount'] ?? 0))->toBe($expectedAmount);
    }
})->with(array_map(
    fn (array $example) => [
        $example['message'],
        $example['expected_intent'],
        $example['expected_domain'],
        $example['expected_amount'] ?? null,
    ],
    $examples,
));

it('exposes active missing fields for budget note reminder and drive follow-ups', function () {
    $user = User::factory()->create();
    $contact = WhatsAppContact::factory()->create([
        'user_id' => $user->id,
        'phone_number' => '5513991290256',
    ]);

    $context = app(AssistantContextBuilder::class)->build($user, $contact);
    $classifier = app(IntentClassifier::class);

    $budget = $classifier->classify('criar orcamento para compras', $context);
    $note = $classifier->classify('anota', $context);
    $reminder = $classifier->classify('me lembra de pagar a academia', $context);
    $drive = $classifier->classify('salva isso no drive', $context);

    expect($budget->intent->value)->toBe('create_budget')
        ->and($budget->missingFields)->toContain('amount')
        ->and($note->intent->value)->toBe('create_note')
        ->and($note->missingFields)->toContain('content')
        ->and($reminder->intent->value)->toBe('create_reminder')
        ->and($reminder->missingFields)->toContain('schedule')
        ->and($drive->intent->value)->toBe('create_drive_file')
        ->and($drive->missingFields)->toContain('file');
});
