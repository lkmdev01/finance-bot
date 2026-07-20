<?php

use App\Models\AbacatePaySubscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('pagina de planos mostra retorno do pagamento e modo de cobranca', function () {
    config(['billing.plans.pro_monthly.checkout_flow' => 'subscription']);

    $user = User::factory()->create([
        'phone_number' => '5511999999999',
        'whatsapp_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->get(route('billing.plans', ['checkout' => 'success']));

    $response->assertOk()
        ->assertSee('Recebemos seu retorno do pagamento')
        ->assertSee('Renovação automática')
        ->assertSee('Renova automaticamente todo mês no cartão')
        ->assertSee('Oferta única')
        ->assertSee('30% de desconto')
        ->assertSee('R$ 19,97')
        ->assertDontSee('Pro Anual');
});

test('pagina de planos nao exibe cancelar assinatura quando nao ha assinatura recorrente ativa', function () {
    config(['billing.plans.pro_yearly.checkout_flow' => 'checkout']);

    $user = User::factory()->create([
        'phone_number' => '5511999999999',
        'whatsapp_verified_at' => now(),
        'billing_plan_code' => 'pro_yearly',
        'billing_plan_status' => 'active',
        'billing_access_ends_at' => now()->addYear(),
    ]);

    $response = $this->actingAs($user)->get(route('billing.plans'));

    $response->assertOk()
        ->assertSee('não está vinculado a uma assinatura recorrente cancelável por aqui', false);
});

test('pagina de planos exibe cancelar assinatura quando ha assinatura recorrente ativa', function () {
    $user = User::factory()->create([
        'phone_number' => '5511999999999',
        'whatsapp_verified_at' => now(),
        'billing_plan_code' => 'pro_monthly',
        'billing_plan_status' => 'active',
        'billing_access_ends_at' => now()->addMonth(),
    ]);

    AbacatePaySubscription::query()->create([
        'user_id' => $user->id,
        'plan_code' => 'pro_monthly',
        'external_id' => 'plan_pro_monthly_'.$user->id.'_abc',
        'gateway_subscription_id' => 'subs_abc123xyz',
        'gateway_customer_id' => 'cust_abc123',
        'status' => 'ACTIVE',
        'frequency' => 'MONTHLY',
    ]);

    $response = $this->actingAs($user)->get(route('billing.plans'));

    $response->assertOk()
        ->assertSee('Cancelar assinatura')
        ->assertSee('Próxima cobrança')
        ->assertSee($user->billing_access_ends_at->format('d/m/Y'))
        ->assertSee('Cancelamento é imediato e irreversível', false);
});

test('usuario pode iniciar checkout do plano pro apos confirmar dados', function () {
    $planPrice = config('billing.plans.pro_monthly.price_cents');
    config(['billing.plans.pro_monthly.product_id' => 'prod_pro_monthly_123']);
    config(['billing.plans.pro_monthly.checkout_flow' => 'subscription']);

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
        'https://api.abacatepay.com/v2/subscriptions/create' => Http::response([
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

        return $request->url() === 'https://api.abacatepay.com/v2/subscriptions/create'
            && $request['items'][0]['id'] === 'prod_pro_monthly_123'
            && $request['items'][0]['quantity'] === 1
            && $request['customerId'] === 'cust_123'
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

test('usuario nao pode iniciar checkout anual desativado', function () {
    config(['billing.plans.pro_yearly.product_id' => 'prod_pro_yearly_123']);
    config(['billing.plans.pro_yearly.checkout_flow' => 'subscription']);
    config(['billing.plans.pro_yearly.sellable' => false]);
    config(['billing.plans.pro_yearly.visible' => false]);
    config(['billing.subscription_methods' => ['CARD']]);

    $user = User::factory()->create([
        'email' => 'annual@example.com',
        'name' => 'Annual User',
        'phone_number' => '5511999999999',
        'tax_id' => '11144477735',
        'abacatepay_customer_id' => 'cust_existing_annual',
    ]);

    Http::fake();

    $response = $this->actingAs($user)
        ->post(route('billing.subscribe', 'pro_yearly'));

    $response->assertRedirect(route('billing.plans'));
    $response->assertSessionHas('status', 'Esta oferta não está mais disponível. Use a oferta única do Pro Mensal.');

    Http::assertNothingSent();

    expect(AbacatePaySubscription::query()->where('user_id', $user->id)->exists())->toBeFalse();
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
    config(['billing.plans.pro_monthly.checkout_flow' => 'subscription']);

    $user = User::factory()->create([
        'email' => 'lukas@example.com',
        'name' => 'Lukas Martins',
        'phone_number' => null,
        'tax_id' => null,
    ]);

    $response = $this->actingAs($user)
        ->get(route('billing.checkout-data.show', 'pro_monthly'));

    $response->assertOk()
        ->assertSee('Configurar WhatsApp')
        ->assertSee('Renovação automática')
        ->assertSee('Renova automaticamente todo mês no cartão');
});

test('checkout invisivel retorna erro json quando falta cpf', function () {
    $user = User::factory()->create([
        'email' => 'lukas@example.com',
        'name' => 'Lukas Martins',
        'phone_number' => '5511999999999',
        'tax_id' => null,
    ]);

    $response = $this->actingAs($user)
        ->postJson(route('billing.subscribe', 'pro_monthly'));

    $response->assertStatus(422)
        ->assertJson([
            'ok' => false,
            'error' => 'missing_billing_requirements',
            'requires_tax_id' => true,
        ]);
});

test('checkout invisivel aceita tax_id inline e retorna redirect_url em json', function () {
    $planPrice = config('billing.plans.pro_monthly.price_cents');
    config(['billing.plans.pro_monthly.product_id' => 'prod_pro_monthly_123']);
    config(['billing.plans.pro_monthly.checkout_flow' => 'subscription']);

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
        'https://api.abacatepay.com/v2/subscriptions/create' => Http::response([
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
        ->postJson(route('billing.subscribe', 'pro_monthly'), [
            'tax_id' => '111.444.777-35',
        ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'redirect_url' => 'https://pay.abacatepay.com/checkout_123',
        ]);

    $subscription = AbacatePaySubscription::query()->where('user_id', $user->id)->latest()->first();

    expect($subscription)->not->toBeNull()
        ->and($subscription->checkout_url)->toBe('https://pay.abacatepay.com/checkout_123');
});
