<?php

use App\Models\AbacatePaySubscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('usuario pode cancelar assinatura ativa via app', function () {
    $user = User::factory()->create([
        'billing_plan_code' => 'pro_monthly',
        'billing_plan_status' => 'active',
        'billing_access_ends_at' => now()->addMonth(),
    ]);

    $subscription = AbacatePaySubscription::query()->create([
        'user_id' => $user->id,
        'plan_code' => 'pro_monthly',
        'external_id' => 'plan_pro_monthly_'.$user->id.'_abc',
        'gateway_subscription_id' => 'subs_abc123xyz',
        'gateway_customer_id' => 'cust_abc123',
        'status' => 'ACTIVE',
        'frequency' => 'MONTHLY',
    ]);

    config([
        'abacatepay.api_key' => 'abacate_dev_123',
        'abacatepay.base_url' => 'https://api.abacatepay.com/v2',
    ]);

    Http::fake([
        'https://api.abacatepay.com/v2/subscriptions/cancel' => Http::response([
            'success' => true,
            'error' => null,
            'data' => [
                'id' => 'subs_abc123xyz',
                'status' => 'CANCELLED',
                'method' => 'CARD',
            ],
        ]),
    ]);

    $response = $this->actingAs($user)->postJson(route('billing.subscription.cancel'));

    $response->assertOk()->assertJson([
        'ok' => true,
    ]);

    $subscription->refresh();
    $user->refresh();

    expect($subscription->status)->toBe('CANCELLED');
    expect($subscription->cancelled_at)->not->toBeNull();
    expect($user->billing_plan_status)->toBe('cancelled');
    expect($user->billing_access_ends_at)->not->toBeNull();
    expect($user->billing_access_ends_at->isPast())->toBeTrue();
});

