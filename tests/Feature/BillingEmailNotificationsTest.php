<?php

use App\Models\AbacatePaySubscription;
use App\Models\AbacatePayWebhookEvent;
use App\Models\User;
use App\Notifications\BillingPaymentFailedNotification;
use App\Notifications\BillingSubscriptionActivatedNotification;
use App\Notifications\BillingSubscriptionCancelledNotification;
use App\Services\AbacatePayWebhookProcessor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

it('sends subscription activated email when webhook grants premium access', function () {
    Notification::fake();

    $user = User::factory()->create([
        'name' => 'Cliente Plano',
        'email' => 'cliente-plano@example.com',
    ]);
    $payload = [
        'id' => 'log_email_subscription_completed',
        'event' => 'subscription.completed',
        'devMode' => false,
        'data' => [
            'subscription' => [
                'id' => 'subs_email_1',
                'amount' => 1997,
                'currency' => 'BRL',
                'method' => 'CARD',
                'frequency' => 'MONTHLY',
                'status' => 'ACTIVE',
                'metadata' => ['plan_code' => 'pro_monthly'],
                'createdAt' => now()->toISOString(),
            ],
            'customer' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
            'payment' => [
                'id' => 'pay_email_1',
                'amount' => 1997,
                'status' => 'PAID',
            ],
        ],
    ];
    $event = AbacatePayWebhookEvent::query()->create([
        'external_id' => $payload['id'],
        'event_name' => $payload['event'],
        'status' => 'pending',
        'payload' => $payload,
        'received_at' => now(),
    ]);

    app(AbacatePayWebhookProcessor::class)->process($event, $payload);

    Notification::assertSentTo($user, BillingSubscriptionActivatedNotification::class);
});

it('sends payment failed email when a payment failure webhook arrives', function () {
    Notification::fake();

    $user = User::factory()->create([
        'email' => 'falha-pagamento@example.com',
        'billing_plan_code' => 'pro_monthly',
        'billing_plan_status' => 'active',
        'billing_access_ends_at' => now()->addWeek(),
    ]);
    $subscription = AbacatePaySubscription::query()->create([
        'user_id' => $user->id,
        'plan_code' => 'pro_monthly',
        'external_id' => 'external_payment_failed',
        'gateway_subscription_id' => 'subs_payment_failed',
        'status' => 'ACTIVE',
        'frequency' => 'MONTHLY',
    ]);
    $payload = [
        'id' => 'log_payment_failed',
        'event' => 'subscription.payment_failed',
        'data' => [
            'subscription' => [
                'id' => 'subs_payment_failed',
                'status' => 'PAYMENT_FAILED',
            ],
            'customer' => [
                'email' => $user->email,
            ],
            'payment' => [
                'id' => 'pay_failed_1',
                'externalId' => 'external_payment_failed',
                'status' => 'FAILED',
                'failureReason' => 'Cartao recusado',
            ],
        ],
    ];
    $event = AbacatePayWebhookEvent::query()->create([
        'external_id' => $payload['id'],
        'event_name' => $payload['event'],
        'status' => 'pending',
        'payload' => $payload,
        'received_at' => now(),
    ]);

    app(AbacatePayWebhookProcessor::class)->process($event, $payload);

    expect($subscription->fresh()->status)->toBe('PAYMENT_FAILED');
    Notification::assertSentTo($user, BillingPaymentFailedNotification::class);
});

it('sends cancellation email when user cancels subscription in app', function () {
    Notification::fake();

    $user = User::factory()->create([
        'billing_plan_code' => 'pro_monthly',
        'billing_plan_status' => 'active',
        'billing_access_ends_at' => now()->addMonth(),
    ]);

    AbacatePaySubscription::query()->create([
        'user_id' => $user->id,
        'plan_code' => 'pro_monthly',
        'external_id' => 'plan_pro_monthly_'.$user->id.'_email',
        'gateway_subscription_id' => 'subs_cancel_email',
        'gateway_customer_id' => 'cust_cancel_email',
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
                'id' => 'subs_cancel_email',
                'status' => 'CANCELLED',
                'method' => 'CARD',
            ],
        ]),
    ]);

    $this->actingAs($user)->postJson(route('billing.subscription.cancel'))->assertOk();

    Notification::assertSentTo($user, BillingSubscriptionCancelledNotification::class);
});
