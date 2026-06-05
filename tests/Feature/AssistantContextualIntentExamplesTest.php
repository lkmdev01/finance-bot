<?php

use App\Assistant\Classifiers\IntentClassifier;
use App\Assistant\Context\AssistantContextBuilder;
use App\Models\User;
use App\Models\WhatsAppContact;

$examples = require __DIR__.'/../Fixtures/assistant_contextual_examples.php';

it('classifies contextual assistant examples predictably', function (string $message, array $state, string $expectedIntent) {
    $user = User::factory()->create();
    $contact = WhatsAppContact::factory()->create([
        'user_id' => $user->id,
        'phone_number' => '5513991290256',
        'conversation_state' => $state,
    ]);

    $context = app(AssistantContextBuilder::class)->build($user, $contact);
    $parsed = app(IntentClassifier::class)->classify($message, $context);

    expect($parsed->intent->value)->toBe($expectedIntent);
})->with(array_map(
    fn (array $example) => [
        $example['message'],
        $example['state'],
        $example['expected_intent'],
    ],
    $examples,
));
