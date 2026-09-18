<?php

use App\Ai\AgentContextInjector;
use App\Ai\FinancialAgent;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\WhatsAppMessageProcessor;
use Laravel\Ai\Ai;

it('processes a structured FinancialAgent response through the SDK', function () {
    config(['ai.use_sdk' => true]);

    $user = User::factory()->create();
    $contact = WhatsAppContact::factory()->create([
        'user_id' => $user->id,
        'phone_number' => '5513999999999',
    ]);

    $contextInjector = Mockery::mock(AgentContextInjector::class);
    $contextInjector->shouldReceive('getContextString')
        ->once()
        ->with($user, $contact)
        ->andReturn('Saldo atual: R$ 100,00');
    app()->instance(AgentContextInjector::class, $contextInjector);

    Ai::fakeAgent(FinancialAgent::class, [[
        'action' => 'create_transaction',
        'reply' => 'Lancamento identificado.',
        'transaction_data' => [
            'type' => 'expense',
            'amount' => 25,
            'description' => 'Uber',
        ],
    ]])->preventStrayPrompts();

    $result = app(WhatsAppMessageProcessor::class)->process(
        'Gastei 25 no Uber',
        $user,
        $contact,
    );

    expect($result['action'])->toBe('create_transaction')
        ->and($result['transaction_data']['description'])->toBe('Uber');

    Ai::assertAgentWasPrompted(
        FinancialAgent::class,
        fn ($prompt) => $prompt->prompt === 'Gastei 25 no Uber'
    );
});
