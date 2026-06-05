<?php

uses(Tests\TestCase::class);

use App\Assistant\Classifiers\IntentClassifier;
use App\Assistant\DTO\AssistantContextDTO;
use App\Assistant\Enums\FinancialIntent;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\MessageClassifier;

it('maps legacy classifier kinds into the assistant financial intents catalog', function () {
    $user = new User(['id' => 1, 'name' => 'Lucas', 'email' => 'lucas@example.com']);
    $contact = new WhatsAppContact(['id' => 1, 'user_id' => 1, 'phone_number' => '5513999999999']);

    $legacyClassifier = Mockery::mock(MessageClassifier::class);
    $legacyClassifier->shouldReceive('classify')
        ->once()
        ->with('qual e meu saldo?', [])
        ->andReturn([
            'kind' => 'query_balance',
            'domain' => 'transaction',
        ]);

    $classifier = new IntentClassifier($legacyClassifier);

    $parsed = $classifier->classify(
        'qual e meu saldo?',
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

    expect($parsed->intent)->toBe(FinancialIntent::QUERY_BALANCE)
        ->and($parsed->domain)->toBe('transaction')
        ->and($parsed->legacyKind)->toBe('query_balance');
});
