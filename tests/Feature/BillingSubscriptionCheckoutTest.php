<?php

use App\Models\AbacatePaySubscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('usuario pode iniciar checkout do plano pro apos confirmar dados', function () {
    $planPrice = config('billing.plans.pro_monthly.price_cents');
    config(['billing.plans.pro_monthly.product_id' => 'prod_pro_monthly_123']);

    $user = User::factory()->create([
        'email' => 'lukas@example.com',
        'name' => 'Lukas Martins',
        'phone_number' => '5511999999999',
        'tax_id' => null,
        'abacatepay_customer_id' => null,
    ]);

    config([
        'abacatepay.api_key' => 'abacate_dev_123',
        'abacatepay.base_url' => 'https://api.abacatepay.com/v2',
    ]);

    Http::fake([
        'https://api.abacatepay.com/v2/customers/create' => Http::response([
            'success' => true,
            'error' => null,
            'data' => [
                'id' => 'cust_123',
            ],
        ]),
        'https://api.abacatepay.com/v2/checkouts/create' => Http::response([
            'success' => true,
            'error' => null,
            'data' => [
                'id' => 'checkout_123',
                'url' => 'https://pay.abacatepay.com/checkout_123',
                'amount' => $planPrice,
                'status' => 'PENDING',
                'customerId' => 'cust_123',
            ],
        ]),
    ]);

    $response = $this->actingAs($user)
        ->post(route('billing.checkout-data.store', 'pro_monthly'), [
            'tax_id' => '111.444.777-35',
        ]);

    $response->assertRedirect('https://pay.abacatepay.com/checkout_123');

    Http::assertSent(function ($request) {
        if ($request->url() === 'https://api.abacatepay.com/v2/customers/create') {
            return $request['name'] === 'Lukas Martins'
                && $request['email'] === 'lukas@example.com'
                && $request['cellphone'] === '(11) 99999-9999'
                && $request['taxId'] === '111.444.777-35';
        }

        return $request->url() === 'https://api.abacatepay.com/v2/checkouts/create'
            && $request['items'][0]['id'] === 'prod_pro_monthly_123'
            && $request['items'][0]['quantity'] === 1
            && $request['customerId'] === 'cust_123'
            && in_array('PIX', $request['methods'] ?? [], true)
            && in_array('CARD', $request['methods'] ?? [], true)
            && ($request['metadata']['plan_code'] ?? null) === 'pro_monthly';
    });

    $user->refresh();

    expect($user->tax_id)->toBe('11144477735');
    expect($user->abacatepay_customer_id)->toBe('cust_123');

    $subscription = AbacatePaySubscription::query()->where('user_id', $user->id)->latest()->first();

    expect($subscription)->not->toBeNull()
        ->and($subscription->plan_code)->toBe('pro_monthly')
        ->and($subscription->gateway_customer_id)->toBe('cust_123')
        ->and($subscription->gateway_checkout_id)->toBe('checkout_123')
        ->and($subscription->checkout_url)->toBe('https://pay.abacatepay.com/checkout_123')
        ->and($subscription->customer_tax_id)->toBe('111.444.777-35');
});

test('usuario sem cpf e redirecionado para a tela de confirmacao antes do checkout', function () {
    $user = User::factory()->create([
        'email' => 'lukas@example.com',
        'name' => 'Lukas Martins',
        'phone_number' => '5511999999999',
        'tax_id' => null,
    ]);

    $response = $this->actingAs($user)
        ->from(route('billing.plans'))
        ->post(route('billing.subscribe', 'pro_monthly'));

    $response->assertRedirect(route('billing.checkout-data.show', 'pro_monthly'));
    $response->assertSessionHas('status', 'Confirme seus dados antes de seguir para o checkout.');
});

test('usuario sem numero cadastrado continua vendo a tela intermediaria', function () {
    $user = User::factory()->create([
        'email' => 'lukas@example.com',
        'name' => 'Lukas Martins',
        'phone_number' => null,
        'tax_id' => null,
    ]);

    $response = $this->actingAs($user)
        ->get(route('billing.checkout-data.show', 'pro_monthly'));

    $response->assertOk()
        ->assertSee('Configurar WhatsApp');
});

