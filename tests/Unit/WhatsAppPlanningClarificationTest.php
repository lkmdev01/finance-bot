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
}
