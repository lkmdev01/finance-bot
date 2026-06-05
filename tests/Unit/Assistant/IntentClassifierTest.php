<?php

uses(Tests\TestCase::class);

use App\Assistant\Classifiers\IntentClassifier;
use App\Assistant\DTO\AssistantContextDTO;
use App\Assistant\Enums\FinancialIntent;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\BudgetIntentClassifier;
use App\Services\WhatsApp\BudgetMessageParser;
use App\Services\WhatsApp\DriveIntentClassifier;
use App\Services\WhatsApp\DriveMessageParser;
use App\Services\WhatsApp\MessageClassifier;
use App\Services\WhatsApp\NoteIntentClassifier;
use App\Services\WhatsApp\NoteMessageParser;
use App\Services\WhatsApp\PlanningIntentClassifier;
use App\Services\WhatsApp\RecurringTransactionMessageParser;
use App\Services\WhatsApp\ReminderIntentClassifier;
use App\Services\WhatsApp\ReminderMessageParser;
use App\Services\WhatsApp\SavingsGoalMessageParser;
use App\Services\WhatsApp\SimpleTransactionMessageParser;
use App\Services\WhatsApp\SubscriptionMessageParser;

it('maps legacy classifier kinds into the assistant financial intents catalog', function () {
    $user = new User(['id' => 1, 'name' => 'Lucas', 'email' => 'lucas@example.com']);
    $contact = new WhatsAppContact(['id' => 1, 'user_id' => 1, 'phone_number' => '5513999999999']);

    $legacyClassifier = Mockery::mock(MessageClassifier::class);
    $legacyClassifier->shouldNotReceive('classify');

    $simpleParser = Mockery::mock(SimpleTransactionMessageParser::class);
    $simpleParser->shouldNotReceive('parse');

    $classifier = new IntentClassifier(
        $legacyClassifier,
        $simpleParser,
        Mockery::mock(BudgetIntentClassifier::class),
        Mockery::mock(BudgetMessageParser::class),
        Mockery::mock(NoteIntentClassifier::class),
        Mockery::mock(NoteMessageParser::class),
        Mockery::mock(PlanningIntentClassifier::class),
        Mockery::mock(SavingsGoalMessageParser::class),
        Mockery::mock(SubscriptionMessageParser::class),
        Mockery::mock(RecurringTransactionMessageParser::class),
        Mockery::mock(ReminderIntentClassifier::class),
        Mockery::mock(ReminderMessageParser::class),
        Mockery::mock(DriveIntentClassifier::class),
        Mockery::mock(DriveMessageParser::class),
    );

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
