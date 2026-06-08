<?php

use App\Assistant\Classifiers\IntentClassifier;
use App\Assistant\Context\AssistantContextBuilder;
use App\Models\User;
use App\Models\WhatsAppContact;

$examples = require __DIR__.'/../Fixtures/assistant_examples.php';

it('classifies real assistant examples predictably', function (string $message, string $expectedIntent, ?float $expectedAmount = null, ?string $expectedType = null) {
    $user = User::factory()->create();
    $contact = WhatsAppContact::factory()->create([
        'user_id' => $user->id,
        'phone_number' => '5513991290256',
    ]);

    $context = app(AssistantContextBuilder::class)->build($user, $contact);
    $parsed = app(IntentClassifier::class)->classify($message, $context);

    expect($parsed->intent->value)->toBe($expectedIntent);

    if ($expectedAmount !== null) {
        expect((float) ($parsed->data['amount'] ?? 0))->toBe($expectedAmount);
    }

    if ($expectedType !== null) {
        expect($parsed->data['type'] ?? null)->toBe($expectedType);
    }
})->with(array_map(
    fn (array $example) => [
        $example['message'],
        $example['expected_intent'],
        $example['expected_amount'] ?? null,
        $example['expected_type'] ?? null,
    ],
    $examples,
));
