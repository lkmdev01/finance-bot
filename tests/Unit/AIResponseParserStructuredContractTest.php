<?php

use App\Services\AIResponseParser;

it('normalizes the structured assistant contract into the legacy action payload', function () {
    $parser = new AIResponseParser();

    $payload = json_encode([
        'intent' => 'create_expense',
        'confidence' => 0.97,
        'data' => [
            'type' => 'expense',
            'amount' => 50.0,
            'description' => 'Mercado',
            'date' => '2026-06-05',
        ],
        'missing_fields' => [],
        'needs_confirmation' => false,
        'user_friendly_summary' => 'Despesa de R$ 50,00 no mercado',
    ]);

    $parsed = $parser->parse($payload);

    expect($parsed['intent'])->toBe('create_expense')
        ->and($parsed['action'])->toBe('create_transaction')
        ->and($parsed['reply'])->toBe('Despesa de R$ 50,00 no mercado')
        ->and($parsed['transaction_data']['type'])->toBe('expense')
        ->and((float) $parsed['transaction_data']['amount'])->toBe(50.0);
});

it('normalizes note contracts into note payloads', function () {
    $parser = new AIResponseParser();

    $payload = json_encode([
        'intent' => 'create_note',
        'confidence' => 0.91,
        'data' => [
            'title' => 'Ligar Para O Contador',
            'body' => 'ligar para o contador',
            'source' => 'whatsapp',
        ],
        'missing_fields' => [],
        'needs_confirmation' => false,
        'user_friendly_summary' => 'Nota pronta para salvar',
    ]);

    $parsed = $parser->parse($payload);

    expect($parsed['intent'])->toBe('create_note')
        ->and($parsed['action'])->toBe('create_note')
        ->and($parsed['note_data']['title'])->toBe('Ligar Para O Contador');
});

it('normalizes subscription contracts into subscription payloads', function () {
    $parser = new AIResponseParser();

    $payload = json_encode([
        'intent' => 'create_subscription',
        'confidence' => 0.9,
        'data' => [
            'name' => 'Netflix',
            'amount' => 19.0,
            'billing_cycle' => 'monthly',
            'due_day' => 10,
        ],
        'missing_fields' => [],
        'needs_confirmation' => false,
        'user_friendly_summary' => 'Assinatura pronta para salvar',
    ]);

    $parsed = $parser->parse($payload);

    expect($parsed['intent'])->toBe('create_subscription')
        ->and($parsed['action'])->toBe('create_subscription')
        ->and($parsed['subscription_data']['name'])->toBe('Netflix');
});
