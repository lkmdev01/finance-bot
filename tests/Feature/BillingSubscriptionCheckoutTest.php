<?php

use App\Models\AbacatePaySubscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('usuario pode iniciar checkout de assinatura do plano pro', function () {
    $user = User::factory()->create([
        'email' => 'lukas@example.com',
        'name' => 'Lukas Martins',
        'phone_number' => '5511999999999',
    ]);

    config([
        'abacatepay.api_key' => 'abacate_dev_123',
        'billing.plans.pro_monthly.product_id' => 'prod_pro_monthly_123',
    ]);

    Http::fake([
        'https://api.abacatepay.com/v2/customers/create' => Http::response([
            'success' => true,
            'error' => null,
            'data' => [
                'id' => 'cust_abc123xyz',
                'email' => 'lukas@example.com',
            ],
        ]),
        'https://api.abacatepay.com/v2/subscriptions/create' => Http::response([
            'success' => true,
            'error' => null,
            'data' => [
                'id' => 'bill_sub_123',
                'externalId' => 'plan_pro_monthly_1_x',
                'url' => 'https://app.abacatepay.com/pay/bill_sub_123',
                'amount' => 2990,
                'status' => 'PENDING',
                'customerId' => 'cust_abc123xyz',
                'createdAt' => '2026-04-01T12:00:00.000Z',
                'updatedAt' => '2026-04-01T12:00:00.000Z',
            ],
        ]),
    ]);

    $response = $this->actingAs($user)
        ->post(route('billing.subscribe', 'pro_monthly'));

    $response->assertRedirect('https://app.abacatepay.com/pay/bill_sub_123');

    $user->refresh();

    expect($user->abacatepay_customer_id)->toBe('cust_abc123xyz');

    $subscription = AbacatePaySubscription::query()->where('user_id', $user->id)->latest()->first();

    expect($subscription)->not->toBeNull()
        ->and($subscription->plan_code)->toBe('pro_monthly')
        ->and($subscription->gateway_customer_id)->toBe('cust_abc123xyz')
        ->and($subscription->gateway_checkout_id)->toBe('bill_sub_123')
        ->and($subscription->checkout_url)->toBe('https://app.abacatepay.com/pay/bill_sub_123');
});
