<?php

use App\Models\AbacatePaySubscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('usuario pode iniciar checkout de assinatura do plano pro apos confirmar dados', function () {
    $user = User::factory()->create([
        'email' => 'lukas@example.com',
        'name' => 'Lukas Martins',
        'phone_number' => '5511999999999',
        'tax_id' => null,
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
        ->post(route('billing.checkout-data.store', 'pro_monthly'), [
            'tax_id' => '111.444.777-35',
        ]);

    $response->assertRedirect('https://pay.abacatepay.com/bill_sub_123');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.abacatepay.com/v1/billing/create'
            && $request['products'][0]['externalId'] === 'pro_monthly'
            && $request['products'][0]['price'] === 2990
            && $request['frequency'] === 'ONE_TIME'
            && $request['customer']['name'] === 'Lukas Martins'
            && $request['customer']['email'] === 'lukas@example.com'
            && $request['customer']['cellphone'] === '(11) 99999-9999'
            && $request['customer']['taxId'] === '111.444.777-35';
    });

    $user->refresh();

    expect($user->tax_id)->toBe('11144477735');

    $subscription = AbacatePaySubscription::query()->where('user_id', $user->id)->latest()->first();

    expect($subscription)->not->toBeNull()
        ->and($subscription->plan_code)->toBe('pro_monthly')
        ->and($subscription->gateway_customer_id)->toBeNull()
        ->and($subscription->gateway_checkout_id)->toBe('bill_sub_123')
        ->and($subscription->checkout_url)->toBe('https://pay.abacatepay.com/bill_sub_123')
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
