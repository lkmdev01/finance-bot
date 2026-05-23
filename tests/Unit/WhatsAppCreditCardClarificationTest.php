<?php

namespace Tests\Unit;

use App\Services\WhatsApp\Resolvers\ClarificationResolver;
use Tests\TestCase;

class WhatsAppCreditCardClarificationTest extends TestCase
{
    public function test_select_credit_card_clarification_parses_card_name(): void
    {
        $resolver = app(ClarificationResolver::class);

        $state = [
            'pending_intent' => 'select_credit_card',
            'pending_payload' => [
                'transaction_data' => [
                    'type' => 'expense',
                    'amount' => 120.0,
                    'payment_method' => 'credit',
                ],
            ],
        ];

        $result = $resolver->resolve('cartão Nubank', $state);

        $this->assertFalse($result['handled']);
        $this->assertSame('create_transaction', $result['result']['action']);
        $this->assertSame('Nubank', $result['result']['transaction_data']['credit_card_name']);
        $this->assertArrayNotHasKey('use_default_card', $result['result']['transaction_data']);
    }

    public function test_select_credit_card_clarification_uses_default_card(): void
    {
        $resolver = app(ClarificationResolver::class);

        $state = [
            'pending_intent' => 'select_credit_card',
            'pending_payload' => [
                'transaction_data' => [
                    'type' => 'expense',
                    'amount' => 75.0,
                    'payment_method' => 'credit',
                ],
            ],
        ];

        $result = $resolver->resolve('usar cartão padrão', $state);

        $this->assertFalse($result['handled']);
        $this->assertSame('create_transaction', $result['result']['action']);
        $this->assertSame(true, $result['result']['transaction_data']['use_default_card']);
        $this->assertArrayNotHasKey('credit_card_name', $result['result']['transaction_data']);
    }
}
