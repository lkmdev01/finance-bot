<?php

namespace Tests\Unit;

use App\Services\WhatsApp\Resolvers\ClarificationResolver;
use Tests\TestCase;

class WhatsAppPlanningClarificationTest extends TestCase
{
    public function test_create_savings_goal_clarification_merges_amount_follow_up(): void
    {
        $resolver = app(ClarificationResolver::class);

        $state = [
            'pending_intent' => 'create_savings_goal_details',
            'pending_payload' => [
                'goal_data' => [
                    'name' => 'Viagem',
                ],
            ],
        ];

        $result = $resolver->resolve('5000 ate dezembro de 2026', $state);

        $this->assertFalse($result['handled']);
        $this->assertSame('create_savings_goal', $result['result']['action']);
        $this->assertSame('Viagem', $result['result']['goal_data']['name']);
        $this->assertSame(5000.0, $result['result']['goal_data']['target_amount']);
    }

    public function test_create_subscription_clarification_merges_due_day_and_amount_follow_up(): void
    {
        $resolver = app(ClarificationResolver::class);

        $state = [
            'pending_intent' => 'create_subscription_details',
            'pending_payload' => [
                'subscription_data' => [
                    'name' => 'Netflix',
                    'billing_cycle' => 'monthly',
                ],
            ],
        ];

        $result = $resolver->resolve('39,90 dia 10', $state);

        $this->assertFalse($result['handled']);
        $this->assertSame('create_subscription', $result['result']['action']);
        $this->assertSame('Netflix', $result['result']['subscription_data']['name']);
        $this->assertSame('monthly', $result['result']['subscription_data']['billing_cycle']);
        $this->assertSame(39.90, $result['result']['subscription_data']['amount']);
        $this->assertSame(10, $result['result']['subscription_data']['due_day']);
    }

    public function test_update_savings_goal_clarification_merges_follow_up_change(): void
    {
        $resolver = app(ClarificationResolver::class);

        $state = [
            'pending_intent' => 'update_savings_goal_details',
            'pending_payload' => [
                'goal_data' => [
                    'name' => 'Viagem',
                ],
            ],
        ];

        $result = $resolver->resolve('para 7000', $state);

        $this->assertFalse($result['handled']);
        $this->assertSame('update_savings_goal', $result['result']['action']);
        $this->assertSame('Viagem', $result['result']['goal_data']['name']);
        $this->assertSame(7000.0, $result['result']['goal_data']['target_amount']);
    }

    public function test_cancel_subscription_clarification_accepts_short_target_name(): void
    {
        $resolver = app(ClarificationResolver::class);

        $state = [
            'pending_intent' => 'cancel_subscription_target',
            'pending_payload' => [
                'subscription_data' => [],
            ],
        ];

        $result = $resolver->resolve('Netflix', $state);

        $this->assertFalse($result['handled']);
        $this->assertSame('cancel_subscription', $result['result']['action']);
        $this->assertSame('Netflix', $result['result']['subscription_data']['name']);
    }

    public function test_update_recurring_clarification_merges_short_follow_up_change(): void
    {
        $resolver = app(ClarificationResolver::class);

        $state = [
            'pending_intent' => 'update_recurring_transaction_details',
            'pending_payload' => [
                'recurring_data' => [
                    'description' => 'Academia',
                ],
            ],
        ];

        $result = $resolver->resolve('para 99', $state);

        $this->assertFalse($result['handled']);
        $this->assertSame('update_recurring_transaction', $result['result']['action']);
        $this->assertSame('Academia', $result['result']['recurring_data']['description']);
        $this->assertSame(99.0, $result['result']['recurring_data']['amount']);
    }
}
