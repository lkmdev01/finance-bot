<?php

uses(Tests\TestCase::class);

use App\Ai\FinancialAgent;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\AIService;
use App\Services\SpecializedAiFallbackService;
use App\Services\WhatsAppMessageProcessor;

it('processes structured responses through the FinancialAgent when the SDK is enabled', function () {
    config(['ai.use_sdk' => true]);

    $user = new User(['id' => 1]);
    $contact = new WhatsAppContact(['id' => 10, 'user_id' => 1]);
    $result = [
        'action' => 'create_transaction',
        'reply' => 'Lancamento identificado.',
        'transaction_data' => [
            'type' => 'expense',
            'amount' => 25,
            'description' => 'Uber',
        ],
    ];

    $agent = Mockery::mock(FinancialAgent::class);
    $agent->shouldReceive('forUser')->once()->with($contact)->andReturnSelf();
    $fallback = Mockery::mock(SpecializedAiFallbackService::class);
    $fallback->shouldReceive('prompt')->once()
        ->with($user, $agent, 'Gastei 25 no Uber', 'financial')
        ->andReturn($result);

    app()->bind(FinancialAgent::class, fn () => $agent);

    $processor = new WhatsAppMessageProcessor(Mockery::mock(AIService::class), $fallback);
    $result = $processor->process('Gastei 25 no Uber', $user, $contact);

    expect($result)->toMatchArray([
        'action' => 'create_transaction',
        'reply' => 'Lancamento identificado.',
    ])
        ->and($result['transaction_data']['description'])->toBe('Uber');
});

it('returns a friendly safe reply when the financial model and its fallback are rate limited', function () {
    config(['ai.use_sdk' => true]);

    $user = new User(['id' => 7]);
    $contact = new WhatsAppContact(['id' => 10, 'user_id' => 7]);

    $agent = Mockery::mock(FinancialAgent::class);
    $agent->shouldReceive('forUser')->once()->with($contact)->andReturnSelf();
    app()->bind(FinancialAgent::class, fn () => $agent);

    $fallback = Mockery::mock(SpecializedAiFallbackService::class);
    $fallback->shouldReceive('prompt')->once()
        ->with($user, $agent, 'Você é ruim', 'financial')
        ->andThrow(Laravel\Ai\Exceptions\RateLimitedException::forProvider('groq'));

    $processor = new WhatsAppMessageProcessor(Mockery::mock(AIService::class), $fallback);
    $result = $processor->process('Você é ruim', $user, $contact);

    expect($result['action'])->toBeNull()
        ->and($result['reply'])->toContain('não consegui responder direito')
        ->and($result['reply'])->toContain('Não fiz nenhuma alteração financeira');
});
