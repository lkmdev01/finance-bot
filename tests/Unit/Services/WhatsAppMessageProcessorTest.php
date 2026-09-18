<?php

uses(Tests\TestCase::class);

use App\Ai\FinancialAgent;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\AIService;
use App\Services\WhatsAppMessageProcessor;
use Laravel\Ai\Responses\AgentResponse;

it('processes structured responses through the FinancialAgent when the SDK is enabled', function () {
    config(['ai.use_sdk' => true]);

    $user = new User(['id' => 1]);
    $contact = new WhatsAppContact(['id' => 10, 'user_id' => 1]);
    $response = Mockery::mock(AgentResponse::class);
    $response->shouldReceive('toArray')->once()->andReturn([
        'action' => 'create_transaction',
        'reply' => 'Lancamento identificado.',
        'transaction_data' => [
            'type' => 'expense',
            'amount' => 25,
            'description' => 'Uber',
        ],
    ]);

    $agent = Mockery::mock(FinancialAgent::class);
    $agent->shouldReceive('forUser')->once()->with($contact)->andReturnSelf();
    $agent->shouldReceive('prompt')->once()->with('Gastei 25 no Uber')->andReturn($response);

    app()->bind(FinancialAgent::class, fn () => $agent);

    $processor = new WhatsAppMessageProcessor(Mockery::mock(AIService::class));
    $result = $processor->process('Gastei 25 no Uber', $user, $contact);

    expect($result)->toMatchArray([
        'action' => 'create_transaction',
        'reply' => 'Lancamento identificado.',
    ])
        ->and($result['transaction_data']['description'])->toBe('Uber');
});
