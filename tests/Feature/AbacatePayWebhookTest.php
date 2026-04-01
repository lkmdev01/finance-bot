<?php

use App\Models\AbacatePayCharge;
use App\Models\AbacatePaySubscription;
use App\Models\AbacatePayWebhookEvent;
use App\Models\User;
use Carbon\Carbon;

function abacateSignature(array $payload, string $publicKey): string
{
    return base64_encode(hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE), $publicKey, true));
}

test('webhook da abacatepay exige secret valido', function () {
    config([
        'abacatepay.webhook_secret' => 'segredo-correto',
        'abacatepay.public_hmac_key' => 'public-key-123',
    ]);

    $payload = [
        'id' => 'log_123',
        'event' => 'transparent.completed',
        'apiVersion' => 2,
        'devMode' => true,
        'data' => [
            'transparent' => [
                'id' => 'char_123',
                'status' => 'PAID',
            ],
        ],
    ];

    $response = $this->withHeaders([
        'X-Webhook-Signature' => abacateSignature($payload, 'public-key-123'),
    ])->postJson('/webhook/abacatepay?webhookSecret=segredo-invalido', $payload);

    $response->assertUnauthorized()
        ->assertJson(['error' => 'unauthorized']);
});

test('webhook da abacatepay exige assinatura valida', function () {
    config([
        'abacatepay.webhook_secret' => 'segredo-correto',
        'abacatepay.public_hmac_key' => 'public-key-123',
    ]);

    $payload = [
        'id' => 'log_123',
        'event' => 'transparent.completed',
        'apiVersion' => 2,
        'devMode' => true,
        'data' => [],
    ];

    $response = $this->withHeaders([
        'X-Webhook-Signature' => 'assinatura-invalida',
    ])->postJson('/webhook/abacatepay?webhookSecret=segredo-correto', $payload);

    $response->assertUnauthorized()
        ->assertJson(['error' => 'invalid_signature']);
});

test('webhook da abacatepay registra evento valido com idempotencia', function () {
    config([
        'abacatepay.webhook_secret' => 'segredo-correto',
        'abacatepay.public_hmac_key' => 'public-key-123',
    ]);

    $payload = [
        'id' => 'log_abc123xyz',
        'event' => 'subscription.renewed',
        'apiVersion' => 2,
        'devMode' => false,
        'data' => [
            'subscription' => [
                'id' => 'subs_123',
                'status' => 'ACTIVE',
            ],
        ],
    ];

    $signature = abacateSignature($payload, 'public-key-123');

    $firstResponse = $this->withHeaders([
        'X-Webhook-Signature' => $signature,
    ])->postJson('/webhook/abacatepay?webhookSecret=segredo-correto', $payload);

    $firstResponse->assertSuccessful()
        ->assertJson([
            'success' => true,
            'status' => 'processed',
            'event' => 'subscription.renewed',
        ]);

    $this->assertDatabaseHas('abacate_pay_webhook_events', [
        'external_id' => 'log_abc123xyz',
        'event_name' => 'subscription.renewed',
        'status' => 'processed',
    ]);

    expect(AbacatePayWebhookEvent::count())->toBe(1);

    $secondResponse = $this->withHeaders([
        'X-Webhook-Signature' => $signature,
    ])->postJson('/webhook/abacatepay?webhookSecret=segredo-correto', $payload);

    $secondResponse->assertSuccessful()
        ->assertJson([
            'success' => true,
            'status' => 'already_processed',
            'event' => 'subscription.renewed',
        ]);

    expect(AbacatePayWebhookEvent::count())->toBe(1);
});

test('webhook da abacatepay reprocessa evento que falhou anteriormente', function () {
    config([
        'abacatepay.webhook_secret' => 'segredo-correto',
        'abacatepay.public_hmac_key' => 'public-key-123',
    ]);

    $user = User::factory()->create([
        'email' => 'retry@example.com',
    ]);

    AbacatePaySubscription::create([
        'user_id' => $user->id,
        'plan_code' => 'pro_monthly',
        'external_id' => 'plan_retry_1',
        'gateway_checkout_id' => 'bill_retry_1',
        'amount' => 100,
        'status' => 'PENDING',
        'frequency' => 'MONTHLY',
        'customer_email' => 'retry@example.com',
        'payload' => [],
    ]);

    AbacatePayWebhookEvent::create([
        'external_id' => 'log_retry_1',
        'event_name' => 'billing.paid',
        'api_version' => 2,
        'dev_mode' => false,
        'status' => 'failed',
        'payload' => ['old' => true],
        'received_at' => now()->subMinute(),
        'processed_at' => now()->subMinute(),
        'error_message' => 'temporary_failure',
    ]);

    $payload = [
        'id' => 'log_retry_1',
        'event' => 'billing.paid',
        'devMode' => false,
        'data' => [
            'billing' => [
                'id' => 'bill_retry_1',
                'kind' => ['PIX'],
                'amount' => 100,
                'status' => 'PAID',
                'customer' => [
                    'id' => 'cust_retry_1',
                    'metadata' => [
                        'name' => 'Retry User',
                        'email' => 'retry@example.com',
                        'taxId' => '11144477735',
                    ],
                ],
                'products' => [
                    [
                        'publicId' => 'prod_retry_1',
                        'quantity' => 1,
                        'externalId' => 'pro_monthly',
                    ],
                ],
                'frequency' => 'ONE_TIME',
                'paidAmount' => 100,
                'couponsUsed' => [],
            ],
            'payment' => [
                'fee' => 80,
                'amount' => 100,
                'method' => 'PIX',
            ],
        ],
    ];

    $response = $this->withHeaders([
        'X-Webhook-Signature' => abacateSignature($payload, 'public-key-123'),
    ])->postJson('/webhook/abacatepay?webhookSecret=segredo-correto', $payload);

    $response->assertSuccessful()
        ->assertJson([
            'success' => true,
            'status' => 'processed',
            'event' => 'billing.paid',
        ]);

    $event = AbacatePayWebhookEvent::query()->where('external_id', 'log_retry_1')->first();

    expect($event)->not->toBeNull()
        ->and($event->status)->toBe('processed')
        ->and($event->error_message)->toBeNull();

    $user->refresh();

    expect($user->billing_plan_code)->toBe('pro_monthly')
        ->and($user->billing_plan_status)->toBe('active');
});

test('webhook da abacatepay atualiza cobranca transparente com usuario por email', function () {
    config([
        'abacatepay.webhook_secret' => 'segredo-correto',
        'abacatepay.public_hmac_key' => 'public-key-123',
    ]);

    $user = User::factory()->create([
        'email' => 'lukas@example.com',
    ]);

    $payload = [
        'id' => 'log_transparent_1',
        'event' => 'transparent.completed',
        'apiVersion' => 2,
        'devMode' => false,
        'data' => [
            'transparent' => [
                'id' => 'char_xyz789',
                'externalId' => 'pedido-456',
                'amount' => 5000,
                'paidAmount' => 5000,
                'status' => 'PAID',
                'methods' => ['PIX'],
                'receiptUrl' => 'https://app.abacatepay.com/receipt/abc',
                'updatedAt' => '2026-04-01T12:00:05.000Z',
            ],
            'customer' => [
                'name' => 'Lukas Martins',
                'email' => 'lukas@example.com',
                'taxId' => '123.***.***-**',
            ],
        ],
    ];

    $signature = abacateSignature($payload, 'public-key-123');

    $response = $this->withHeaders([
        'X-Webhook-Signature' => $signature,
    ])->postJson('/webhook/abacatepay?webhookSecret=segredo-correto', $payload);

    $response->assertSuccessful()
        ->assertJson(['status' => 'processed']);

    $this->assertDatabaseHas('abacate_pay_charges', [
        'user_id' => $user->id,
        'gateway_charge_id' => 'char_xyz789',
        'external_id' => 'pedido-456',
        'status' => 'PAID',
    ]);
});

test('webhook da abacatepay libera acesso premium quando checkout do plano e pago', function () {
    Carbon::setTestNow('2026-04-01 10:00:00');

    config([
        'abacatepay.webhook_secret' => 'segredo-correto',
        'abacatepay.public_hmac_key' => 'public-key-123',
    ]);

    $user = User::factory()->create([
        'email' => 'plan@example.com',
    ]);

    AbacatePaySubscription::create([
        'user_id' => $user->id,
        'plan_code' => 'pro_monthly',
        'external_id' => 'pedido-plano-1',
        'gateway_checkout_id' => 'bill_plan_1',
        'checkout_url' => 'https://pay.abacatepay.com/bill_plan_1',
        'amount' => 2990,
        'status' => 'PENDING',
        'frequency' => 'MONTHLY',
        'payload' => [],
    ]);

    $payload = [
        'id' => 'log_checkout_plan_paid',
        'event' => 'checkout.completed',
        'apiVersion' => 2,
        'devMode' => false,
        'data' => [
            'checkout' => [
                'id' => 'bill_plan_1',
                'externalId' => 'pedido-plano-1',
                'url' => 'https://pay.abacatepay.com/bill_plan_1',
                'amount' => 2990,
                'paidAmount' => 2990,
                'status' => 'PAID',
                'methods' => ['CARD'],
                'updatedAt' => '2026-04-01T10:00:05.000Z',
            ],
            'customer' => [
                'name' => 'Plano User',
                'email' => 'plan@example.com',
            ],
        ],
    ];

    $this->withHeaders([
        'X-Webhook-Signature' => abacateSignature($payload, 'public-key-123'),
    ])->postJson('/webhook/abacatepay?webhookSecret=segredo-correto', $payload)
        ->assertSuccessful();

    $user->refresh();

    expect($user->billing_plan_code)->toBe('pro_monthly')
        ->and($user->billing_plan_status)->toBe('active')
        ->and($user->billing_access_ends_at)->not->toBeNull()
        ->and($user->hasFeature('reports'))->toBeTrue();

    $subscription = AbacatePaySubscription::query()->where('external_id', 'pedido-plano-1')->first();

    expect($subscription)->not->toBeNull()
        ->and($subscription->status)->toBe('PAID')
        ->and($subscription->gateway_checkout_id)->toBe('bill_plan_1');
});

test('webhook billing.paid da abacatepay libera acesso premium para fluxo legado', function () {
    Carbon::setTestNow('2026-04-01 15:18:20');

    config([
        'abacatepay.webhook_secret' => 'segredo-correto',
        'abacatepay.public_hmac_key' => 'public-key-123',
    ]);

    $user = User::factory()->create([
        'email' => 'legacy@example.com',
    ]);

    $pending = AbacatePaySubscription::create([
        'user_id' => $user->id,
        'plan_code' => 'pro_monthly',
        'external_id' => 'plan_pro_monthly_1_uuid',
        'gateway_checkout_id' => 'bill_legacy_1',
        'amount' => 100,
        'status' => 'PENDING',
        'frequency' => 'MONTHLY',
        'customer_email' => 'legacy@example.com',
        'payload' => [],
    ]);

    $payload = [
        'id' => 'log_legacy_billing_paid',
        'event' => 'billing.paid',
        'devMode' => false,
        'data' => [
            'billing' => [
                'id' => 'bill_legacy_1',
                'kind' => ['PIX', 'CARD'],
                'amount' => 100,
                'status' => 'PAID',
                'customer' => [
                    'id' => 'cust_legacy_1',
                    'metadata' => [
                        'name' => 'Legacy User',
                        'email' => 'legacy@example.com',
                        'taxId' => '11144477735',
                        'cellphone' => '5511999999999',
                    ],
                ],
                'products' => [
                    [
                        'publicId' => 'prod_legacy_1',
                        'quantity' => 1,
                        'externalId' => 'pro_monthly',
                    ],
                ],
                'frequency' => 'ONE_TIME',
                'paidAmount' => 0,
                'couponsUsed' => [],
            ],
            'payment' => [
                'fee' => 80,
                'amount' => 100,
                'method' => 'PIX',
            ],
        ],
    ];

    $this->withHeaders([
        'X-Webhook-Signature' => abacateSignature($payload, 'public-key-123'),
    ])->postJson('/webhook/abacatepay?webhookSecret=segredo-correto', $payload)
        ->assertSuccessful();

    $user->refresh();

    expect($user->billing_plan_code)->toBe('pro_monthly')
        ->and($user->billing_plan_status)->toBe('active')
        ->and($user->billing_access_ends_at)->not->toBeNull()
        ->and($user->hasFeature('reports'))->toBeTrue();

    expect($pending->fresh()->status)->toBe('PAID')
        ->and($pending->fresh()->gateway_customer_id)->toBe('cust_legacy_1')
        ->and($pending->fresh()->customer_email)->toBe('legacy@example.com');

    $this->assertDatabaseHas('abacate_pay_charges', [
        'user_id' => $user->id,
        'gateway_charge_id' => 'bill_legacy_1',
        'charge_type' => 'billing',
        'status' => 'PAID',
        'amount' => 100,
    ]);
});

test('webhook billing.paid mantem 1 mes de acesso para plano mensal pago no cartao', function () {
    Carbon::setTestNow('2026-04-01 15:18:20');

    config([
        'abacatepay.webhook_secret' => 'segredo-correto',
        'abacatepay.public_hmac_key' => 'public-key-123',
    ]);

    $user = User::factory()->create([
        'email' => 'card@example.com',
    ]);

    $pending = AbacatePaySubscription::create([
        'user_id' => $user->id,
        'plan_code' => 'pro_monthly',
        'external_id' => 'plan_pro_monthly_card_1',
        'gateway_checkout_id' => 'bill_card_monthly_1',
        'amount' => 100,
        'status' => 'PENDING',
        'frequency' => 'MONTHLY',
        'customer_email' => 'card@example.com',
        'payload' => [],
    ]);

    $payload = [
        'id' => 'log_billing_paid_card_monthly',
        'event' => 'billing.paid',
        'devMode' => false,
        'data' => [
            'billing' => [
                'id' => 'bill_card_monthly_1',
                'kind' => ['CARD'],
                'amount' => 100,
                'status' => 'PAID',
                'customer' => [
                    'id' => 'cust_card_monthly_1',
                    'metadata' => [
                        'name' => 'Card User',
                        'email' => 'card@example.com',
                        'taxId' => '11144477735',
                        'cellphone' => '5511999999999',
                    ],
                ],
                'products' => [
                    [
                        'publicId' => 'prod_card_monthly_1',
                        'quantity' => 1,
                        'externalId' => 'pro_monthly',
                    ],
                ],
                'frequency' => 'ONE_TIME',
                'paidAmount' => 100,
                'couponsUsed' => [],
            ],
            'payment' => [
                'fee' => 80,
                'amount' => 100,
                'method' => 'CARD',
            ],
        ],
    ];

    $this->withHeaders([
        'X-Webhook-Signature' => abacateSignature($payload, 'public-key-123'),
    ])->postJson('/webhook/abacatepay?webhookSecret=segredo-correto', $payload)
        ->assertSuccessful();

    $user->refresh();

    expect($user->billing_plan_code)->toBe('pro_monthly')
        ->and($user->billing_plan_status)->toBe('active')
        ->and($user->billing_access_ends_at?->toDateTimeString())->toBe(now()->addMonth()->toDateTimeString());

    expect($pending->fresh()->method)->toBe('CARD')
        ->and($pending->fresh()->status)->toBe('PAID');

    $this->assertDatabaseHas('abacate_pay_charges', [
        'user_id' => $user->id,
        'gateway_charge_id' => 'bill_card_monthly_1',
        'charge_type' => 'billing',
        'method' => 'CARD',
        'status' => 'PAID',
        'amount' => 100,
        'paid_amount' => 100,
    ]);
});

test('webhook billing.paid libera 1 ano de acesso para plano anual', function () {
    Carbon::setTestNow('2026-04-01 15:18:20');

    config([
        'abacatepay.webhook_secret' => 'segredo-correto',
        'abacatepay.public_hmac_key' => 'public-key-123',
    ]);

    $user = User::factory()->create([
        'email' => 'annual@example.com',
    ]);

    AbacatePaySubscription::create([
        'user_id' => $user->id,
        'plan_code' => 'pro_yearly',
        'external_id' => 'plan_pro_yearly_1',
        'gateway_checkout_id' => 'bill_annual_1',
        'amount' => 100,
        'status' => 'PENDING',
        'frequency' => 'YEARLY',
        'customer_email' => 'annual@example.com',
        'payload' => [],
    ]);

    $payload = [
        'id' => 'log_billing_paid_annual',
        'event' => 'billing.paid',
        'devMode' => false,
        'data' => [
            'billing' => [
                'id' => 'bill_annual_1',
                'kind' => ['PIX'],
                'amount' => 100,
                'status' => 'PAID',
                'customer' => [
                    'id' => 'cust_annual_1',
                    'metadata' => [
                        'name' => 'Annual User',
                        'email' => 'annual@example.com',
                        'taxId' => '11144477735',
                        'cellphone' => '5511999999999',
                    ],
                ],
                'products' => [
                    [
                        'publicId' => 'prod_annual_1',
                        'quantity' => 1,
                        'externalId' => 'pro_yearly',
                    ],
                ],
                'frequency' => 'ONE_TIME',
                'paidAmount' => 100,
                'couponsUsed' => [],
            ],
            'payment' => [
                'fee' => 80,
                'amount' => 100,
                'method' => 'PIX',
            ],
        ],
    ];

    $this->withHeaders([
        'X-Webhook-Signature' => abacateSignature($payload, 'public-key-123'),
    ])->postJson('/webhook/abacatepay?webhookSecret=segredo-correto', $payload)
        ->assertSuccessful();

    $user->refresh();

    expect($user->billing_plan_code)->toBe('pro_yearly')
        ->and($user->billing_plan_status)->toBe('active')
        ->and($user->billing_access_ends_at?->toDateTimeString())->toBe(now()->addYear()->toDateTimeString())
        ->and($user->hasFeature('financial_projections'))->toBeTrue();
});

test('webhook da abacatepay cria e atualiza assinatura de billing', function () {
    Carbon::setTestNow('2026-04-01 10:00:00');

    config([
        'abacatepay.webhook_secret' => 'segredo-correto',
        'abacatepay.public_hmac_key' => 'public-key-123',
    ]);

    $user = User::factory()->create([
        'email' => 'billing@example.com',
    ]);

    $completedPayload = [
        'id' => 'log_sub_completed',
        'event' => 'subscription.completed',
        'apiVersion' => 2,
        'devMode' => false,
        'data' => [
            'subscription' => [
                'id' => 'subs_tAFqDWBhcEYTjQh2K0ZYDHau',
                'amount' => 2990,
                'currency' => 'BRL',
                'method' => 'CARD',
                'status' => 'ACTIVE',
                'frequency' => 'MONTHLY',
                'createdAt' => '2026-04-01T10:00:00.000Z',
                'updatedAt' => '2026-04-01T10:00:05.000Z',
                'canceledAt' => null,
            ],
            'customer' => [
                'name' => 'Billing User',
                'email' => 'billing@example.com',
                'taxId' => '12.***.***/0001-**',
            ],
            'payment' => [
                'id' => 'char_pay_1',
                'externalId' => 'pedido-billing-1',
                'amount' => 2990,
                'paidAmount' => 2990,
                'status' => 'PAID',
                'methods' => ['CARD'],
                'receiptUrl' => 'https://app.abacatepay.com/receipt/sub-1',
                'updatedAt' => '2026-04-01T10:00:05.000Z',
            ],
            'checkout' => [
                'id' => 'bill_checkout_1',
                'externalId' => 'pedido-billing-1',
                'customerId' => 'cust_123',
                'metadata' => [
                    'plan_code' => 'pro_monthly',
                ],
            ],
        ],
    ];

    $this->withHeaders([
        'X-Webhook-Signature' => abacateSignature($completedPayload, 'public-key-123'),
    ])->postJson('/webhook/abacatepay?webhookSecret=segredo-correto', $completedPayload)
        ->assertSuccessful();

    $subscription = AbacatePaySubscription::query()->where('gateway_subscription_id', 'subs_tAFqDWBhcEYTjQh2K0ZYDHau')->first();

    expect($subscription)->not->toBeNull();
    expect($subscription->user_id)->toBe($user->id)
        ->and($subscription->plan_code)->toBe('pro_monthly')
        ->and($subscription->status)->toBe('ACTIVE')
        ->and($subscription->gateway_payment_id)->toBe('char_pay_1');

    $user->refresh();

    expect($user->billing_plan_code)->toBe('pro_monthly')
        ->and($user->billing_plan_status)->toBe('active')
        ->and($user->hasFeature('reports'))->toBeTrue();

    $renewedPayload = $completedPayload;
    $renewedPayload['id'] = 'log_sub_renewed';
    $renewedPayload['event'] = 'subscription.renewed';

    $this->withHeaders([
        'X-Webhook-Signature' => abacateSignature($renewedPayload, 'public-key-123'),
    ])->postJson('/webhook/abacatepay?webhookSecret=segredo-correto', $renewedPayload)
        ->assertSuccessful();

    expect($subscription->fresh()->renewed_at)->not->toBeNull();

    $user->refresh();
    expect($user->billing_plan_status)->toBe('renewed')
        ->and($user->billing_access_ends_at)->not->toBeNull();

    $cancelledPayload = $completedPayload;
    $cancelledPayload['id'] = 'log_sub_cancelled';
    $cancelledPayload['event'] = 'subscription.cancelled';
    $cancelledPayload['data']['subscription']['status'] = 'CANCELLED';
    $cancelledPayload['data']['subscription']['canceledAt'] = '2026-04-02T10:00:00.000Z';

    $this->withHeaders([
        'X-Webhook-Signature' => abacateSignature($cancelledPayload, 'public-key-123'),
    ])->postJson('/webhook/abacatepay?webhookSecret=segredo-correto', $cancelledPayload)
        ->assertSuccessful();

    expect($subscription->fresh()->status)->toBe('CANCELLED')
        ->and($subscription->fresh()->cancelled_at)->not->toBeNull();

    $this->assertDatabaseHas('abacate_pay_charges', [
        'user_id' => $user->id,
        'gateway_charge_id' => 'char_pay_1',
        'charge_type' => 'subscription_payment',
        'status' => 'PAID',
    ]);

    expect(AbacatePayCharge::query()->where('gateway_charge_id', 'char_pay_1')->exists())->toBeTrue();

    $user->refresh();
    expect($user->billing_plan_status)->toBe('cancelled')
        ->and($user->billing_plan_code)->toBe('pro_monthly');
});

test('webhook da abacatepay reconcila assinatura pendente criada pelo checkout', function () {
    config([
        'abacatepay.webhook_secret' => 'segredo-correto',
        'abacatepay.public_hmac_key' => 'public-key-123',
    ]);

    $user = User::factory()->create([
        'email' => 'checkout@example.com',
    ]);

    $pending = AbacatePaySubscription::create([
        'user_id' => $user->id,
        'plan_code' => 'pro_monthly',
        'external_id' => 'pedido-checkout-1',
        'gateway_customer_id' => 'cust_123',
        'gateway_checkout_id' => 'bill_checkout_1',
        'checkout_url' => 'https://app.abacatepay.com/pay/bill_checkout_1',
        'amount' => 2990,
        'status' => 'PENDING',
        'frequency' => 'MONTHLY',
        'payload' => [],
    ]);

    $payload = [
        'id' => 'log_sub_checkout_reconcile',
        'event' => 'subscription.completed',
        'apiVersion' => 2,
        'devMode' => false,
        'data' => [
            'subscription' => [
                'id' => 'subs_reconciled_1',
                'amount' => 2990,
                'currency' => 'BRL',
                'method' => 'CARD',
                'status' => 'ACTIVE',
                'frequency' => 'MONTHLY',
                'createdAt' => '2026-04-01T10:00:00.000Z',
                'updatedAt' => '2026-04-01T10:00:05.000Z',
            ],
            'customer' => [
                'name' => 'Checkout User',
                'email' => 'checkout@example.com',
            ],
            'payment' => [
                'id' => 'char_checkout_1',
                'externalId' => 'pedido-checkout-1',
                'amount' => 2990,
                'paidAmount' => 2990,
                'status' => 'PAID',
                'methods' => ['CARD'],
                'updatedAt' => '2026-04-01T10:00:05.000Z',
            ],
            'checkout' => [
                'id' => 'bill_checkout_1',
                'externalId' => 'pedido-checkout-1',
                'customerId' => 'cust_123',
            ],
        ],
    ];

    $this->withHeaders([
        'X-Webhook-Signature' => abacateSignature($payload, 'public-key-123'),
    ])->postJson('/webhook/abacatepay?webhookSecret=segredo-correto', $payload)
        ->assertSuccessful();

    expect(AbacatePaySubscription::count())->toBe(1);
    expect($pending->fresh()->gateway_subscription_id)->toBe('subs_reconciled_1')
        ->and($pending->fresh()->status)->toBe('ACTIVE');
});
