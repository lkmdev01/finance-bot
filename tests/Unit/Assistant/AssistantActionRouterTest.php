<?php

uses(Tests\TestCase::class);

use App\Assistant\DTO\AssistantContextDTO;
use App\Assistant\DTO\IncomingMessageDTO;
use App\Assistant\DTO\ParsedIntentDTO;
use App\Assistant\Enums\FinancialIntent;
use App\Assistant\Routing\AssistantActionRouter;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\ConversationOrchestrator;
use App\Services\WhatsAppMessageProcessor;

it('returns a deterministic create transaction result for direct expense intents', function () {
    $router = new AssistantActionRouter(
        Mockery::mock(ConversationOrchestrator::class),
        Mockery::mock(WhatsAppMessageProcessor::class),
    );

    $result = $router->execute(
        new IncomingMessageDTO(rawMessage: 'gastei 50 no mercado', normalizedMessage: 'gastei 50 no mercado'),
        new AssistantContextDTO(
            user: new User(['id' => 1, 'name' => 'Lucas', 'email' => 'lucas@example.com']),
            contact: new WhatsAppContact(['id' => 1, 'user_id' => 1, 'phone_number' => '5513999999999']),
            state: [],
            timezone: 'America/Sao_Paulo',
            currentMonth: '2026-06',
            currentYear: 2026,
            lastAction: null,
            pendingAction: null,
        ),
        new ParsedIntentDTO(
            intent: FinancialIntent::CREATE_EXPENSE,
            confidence: 0.95,
            data: [
                'type' => 'expense',
                'amount' => 50.0,
                'description' => 'Mercado',
                'date' => '2026-06-05',
            ],
            domain: 'transaction',
            legacyKind: 'transaction_create',
        ),
        [],
    );

    expect($result->usedAI)->toBeFalse()
        ->and($result->result['action'])->toBe('create_transaction')
        ->and((float) $result->result['transaction_data']['amount'])->toBe(50.0);
});

it('returns a deterministic delete transaction result for contextual delete intents', function () {
    $router = new AssistantActionRouter(
        Mockery::mock(ConversationOrchestrator::class),
        Mockery::mock(WhatsAppMessageProcessor::class),
    );

    $result = $router->execute(
        new IncomingMessageDTO(rawMessage: 'apaga o ultimo gasto', normalizedMessage: 'apaga o ultimo gasto'),
        new AssistantContextDTO(
            user: new User(['id' => 1, 'name' => 'Lucas', 'email' => 'lucas@example.com']),
            contact: new WhatsAppContact(['id' => 1, 'user_id' => 1, 'phone_number' => '5513999999999']),
            state: [],
            timezone: 'America/Sao_Paulo',
            currentMonth: '2026-06',
            currentYear: 2026,
            lastAction: 'query_transactions',
            pendingAction: null,
        ),
        new ParsedIntentDTO(
            intent: FinancialIntent::DELETE_TRANSACTION,
            confidence: 0.9,
            data: [
                'transaction_id' => 12,
                'reference' => 'last_transaction',
            ],
            domain: 'transaction',
            legacyKind: 'transaction_delete',
        ),
        [],
    );

    expect($result->usedAI)->toBeFalse()
        ->and($result->result['action'])->toBe('delete_transaction')
        ->and($result->result['transaction_data']['transaction_id'])->toBe(12);
});

it('returns a clarification preflight when budget details are still missing', function () {
    $router = new AssistantActionRouter(
        Mockery::mock(ConversationOrchestrator::class),
        Mockery::mock(WhatsAppMessageProcessor::class),
    );

    $result = $router->execute(
        new IncomingMessageDTO(rawMessage: 'criar orcamento para compras', normalizedMessage: 'criar orcamento para compras'),
        new AssistantContextDTO(
            user: new User(['id' => 1, 'name' => 'Lucas', 'email' => 'lucas@example.com']),
            contact: new WhatsAppContact(['id' => 1, 'user_id' => 1, 'phone_number' => '5513999999999']),
            state: [],
            timezone: 'America/Sao_Paulo',
            currentMonth: '2026-06',
            currentYear: 2026,
            lastAction: null,
            pendingAction: null,
        ),
        new ParsedIntentDTO(
            intent: FinancialIntent::CREATE_BUDGET,
            confidence: 0.83,
            data: ['category_name' => 'Compras'],
            missingFields: ['amount'],
            domain: 'budget',
            legacyKind: 'budget_needs_details',
        ),
        [],
    );

    expect($result->usedAI)->toBeFalse()
        ->and($result->preflight['handled'])->toBeTrue()
        ->and($result->preflight['metadata']['pending_intent'])->toBe('create_budget_details');
});

it('returns a deterministic drive query result for drive intents', function () {
    $router = new AssistantActionRouter(
        Mockery::mock(ConversationOrchestrator::class),
        Mockery::mock(WhatsAppMessageProcessor::class),
    );

    $result = $router->execute(
        new IncomingMessageDTO(rawMessage: 'procura meus arquivos', normalizedMessage: 'procura meus arquivos'),
        new AssistantContextDTO(
            user: new User(['id' => 1, 'name' => 'Lucas', 'email' => 'lucas@example.com']),
            contact: new WhatsAppContact(['id' => 1, 'user_id' => 1, 'phone_number' => '5513999999999']),
            state: [],
            timezone: 'America/Sao_Paulo',
            currentMonth: '2026-06',
            currentYear: 2026,
            lastAction: null,
            pendingAction: null,
        ),
        new ParsedIntentDTO(
            intent: FinancialIntent::QUERY_DRIVE_FILES,
            confidence: 0.91,
            data: ['list_mode' => true],
            domain: 'drive',
            legacyKind: 'drive_query',
        ),
        [],
    );

    expect($result->usedAI)->toBeFalse()
        ->and($result->result['action'])->toBe('query_drive_files')
        ->and($result->result['drive_data']['list_mode'])->toBeTrue();
});
