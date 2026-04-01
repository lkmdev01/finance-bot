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
        'abacatepay.legacy_base_url' => 'https://api.abacatepay.com/v1',
    ]);

    Http::fake([
        'https://api.abacatepay.com/v1/billing/create' => Http::response([
            'success' => true,
            'error' => null,
            'data' => [
                'id' => 'bill_sub_123',
                'url' => 'https://pay.abacatepay.com/bill_sub_123',
                'amount' => 2990,
                'status' => 'PENDING',
                'frequency' => 'ONE_TIME',
            ],
        ]),
    ]);

    $response = $this->actingAs($user)
        ->post(route('billing.subscribe', 'pro_monthly'));

    $response->assertRedirect('https://pay.abacatepay.com/bill_sub_123');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.abacatepay.com/v1/billing/create'
            && $request['products'][0]['externalId'] === 'pro_monthly'
            && $request['products'][0]['price'] === 2990
            && $request['frequency'] === 'ONE_TIME';
    });

    $user->refresh();

    expect($user->abacatepay_customer_id)->toBeNull();

    $subscription = AbacatePaySubscription::query()->where('user_id', $user->id)->latest()->first();

    expect($subscription)->not->toBeNull()
        ->and($subscription->plan_code)->toBe('pro_monthly')
        ->and($subscription->gateway_customer_id)->toBeNull()
        ->and($subscription->gateway_checkout_id)->toBe('bill_sub_123')
        ->and($subscription->checkout_url)->toBe('https://pay.abacatepay.com/bill_sub_123');
});
