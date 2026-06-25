<?php

namespace App\Services;

use App\Models\AbacatePayCharge;
use App\Models\AbacatePaySubscription;
use App\Models\AbacatePayWebhookEvent;
use App\Models\User;
use App\Notifications\BillingPaymentFailedNotification;
use App\Notifications\BillingSubscriptionActivatedNotification;
use App\Notifications\BillingSubscriptionCancelledNotification;
use Carbon\Carbon;

class AbacatePayWebhookProcessor
{
    public function __construct(
        private readonly BillingPlanService $billingPlanService,
    ) {}

    public function process(AbacatePayWebhookEvent $event, array $payload): void
    {
        $eventName = $payload['event'] ?? 'unknown';

        match ($eventName) {
            'billing.paid' => $this->handleLegacyBillingPaid($event, $payload),
            'transparent.completed' => $this->handleTransparentCompleted($event, $payload),
            'checkout.completed' => $this->handleCheckoutCompleted($event, $payload),
            'subscription.completed' => $this->handleSubscriptionUpsert($event, $payload, 'completed'),
            'subscription.renewed' => $this->handleSubscriptionUpsert($event, $payload, 'renewed'),
            'subscription.cancelled' => $this->handleSubscriptionUpsert($event, $payload, 'cancelled'),
            'subscription.failed', 'subscription.payment_failed', 'billing.failed', 'payment.failed' => $this->handlePaymentFailed($event, $payload),
            default => null,
        };
    }

    protected function handleLegacyBillingPaid(AbacatePayWebhookEvent $event, array $payload): void
    {
        $billing = $payload['data']['billing'] ?? [];
        $payment = $payload['data']['payment'] ?? [];
        $customerMetadata = $billing['customer']['metadata'] ?? [];
        $existingSubscription = $this->resolvePendingSubscription(
            gatewayCheckoutId: $billing['id'] ?? null,
            externalId: $billing['externalId'] ?? $event->external_id,
        );

        if (blank($billing['id'] ?? null)) {
            return;
        }

        $user = $this->resolveUser($customerMetadata['email'] ?? null) ?? $existingSubscription?->user;
        $resolvedPlanCode = $this->resolvePlanCode($payload) ?? $existingSubscription?->plan_code;

        AbacatePayCharge::query()->updateOrCreate(
            ['gateway_charge_id' => $billing['id']],
            [
                'user_id' => $user?->id ?? $existingSubscription?->user_id,
                'external_id' => $billing['externalId'] ?? $existingSubscription?->external_id ?? $event->external_id,
                'charge_type' => 'billing',
                'method' => $payment['method'] ?? $billing['kind'][0] ?? null,
                'status' => $billing['status'] ?? 'PAID',
                'amount' => $billing['amount'] ?? $payment['amount'] ?? 0,
                'paid_amount' => $billing['paidAmount'] ?: ($payment['amount'] ?? $billing['amount'] ?? 0),
                'dev_mode' => (bool) ($payload['devMode'] ?? false),
                'customer_name' => $customerMetadata['name'] ?? $existingSubscription?->customer_name,
                'customer_email' => $customerMetadata['email'] ?? $existingSubscription?->customer_email,
                'customer_tax_id' => $customerMetadata['taxId'] ?? $existingSubscription?->customer_tax_id,
                'payload' => $payload,
                'completed_at' => now(),
            ]
        );

        if (blank($resolvedPlanCode)) {
            return;
        }

        $attributes = [
            'user_id' => $user?->id ?? $existingSubscription?->user_id,
            'plan_code' => $resolvedPlanCode,
            'external_id' => $billing['externalId'] ?? $existingSubscription?->external_id ?? $event->external_id,
            'gateway_checkout_id' => $billing['id'],
            'gateway_customer_id' => $billing['customer']['id'] ?? $existingSubscription?->gateway_customer_id,
            'customer_name' => $customerMetadata['name'] ?? $existingSubscription?->customer_name,
            'customer_email' => $customerMetadata['email'] ?? $existingSubscription?->customer_email,
            'customer_tax_id' => $customerMetadata['taxId'] ?? $existingSubscription?->customer_tax_id,
            'amount' => $billing['amount'] ?? $payment['amount'] ?? $existingSubscription?->amount ?? 0,
            'currency' => $existingSubscription?->currency ?? 'BRL',
            'method' => $payment['method'] ?? $billing['kind'][0] ?? $existingSubscription?->method,
            'frequency' => $existingSubscription?->frequency ?? 'MONTHLY',
            'status' => $billing['status'] ?? 'PAID',
            'dev_mode' => (bool) ($payload['devMode'] ?? false),
            'starts_at' => now(),
            'payload' => $payload,
        ];

        if ($existingSubscription) {
            $existingSubscription->update($attributes);
            $record = $existingSubscription->fresh();
        } else {
            $record = AbacatePaySubscription::query()->create($attributes);
        }

        $this->syncUserBillingAccess($user, $record, 'completed');
    }

    protected function handleTransparentCompleted(AbacatePayWebhookEvent $event, array $payload): void
    {
        $transparent = $payload['data']['transparent'] ?? [];
        $customer = $payload['data']['customer'] ?? [];

        if (blank($transparent['id'] ?? null)) {
            return;
        }

        $user = $this->resolveUser($customer['email'] ?? null);

        AbacatePayCharge::query()->updateOrCreate(
            ['gateway_charge_id' => $transparent['id']],
            [
                'user_id' => $user?->id,
                'external_id' => $transparent['externalId'] ?? $event->external_id,
                'charge_type' => 'transparent',
                'method' => $transparent['methods'][0] ?? ($transparent['method'] ?? null),
                'status' => $transparent['status'] ?? 'PAID',
                'amount' => $transparent['amount'] ?? 0,
                'paid_amount' => $transparent['paidAmount'] ?? $transparent['amount'] ?? 0,
                'receipt_url' => $transparent['receiptUrl'] ?? null,
                'dev_mode' => (bool) ($payload['devMode'] ?? false),
                'customer_name' => $customer['name'] ?? null,
                'customer_email' => $customer['email'] ?? null,
                'customer_tax_id' => $customer['taxId'] ?? null,
                'payload' => $payload,
                'completed_at' => $this->parseDate($transparent['updatedAt'] ?? null),
            ]
        );
    }

    protected function handleCheckoutCompleted(AbacatePayWebhookEvent $event, array $payload): void
    {
        $checkout = $payload['data']['checkout'] ?? [];
        $customer = $payload['data']['customer'] ?? [];
        $existingSubscription = $this->resolvePendingSubscription(
            gatewayCheckoutId: $checkout['id'] ?? null,
            externalId: $checkout['externalId'] ?? $event->external_id,
        );

        if (blank($checkout['id'] ?? null)) {
            return;
        }

        $user = $this->resolveUser($customer['email'] ?? null) ?? $existingSubscription?->user;
        $resolvedPlanCode = $this->resolvePlanCode($payload) ?? $existingSubscription?->plan_code;

        AbacatePayCharge::query()->updateOrCreate(
            ['gateway_charge_id' => $checkout['id']],
            [
                'user_id' => $user?->id ?? $existingSubscription?->user_id,
                'external_id' => $checkout['externalId'] ?? $event->external_id,
                'charge_type' => 'checkout',
                'method' => $checkout['methods'][0] ?? null,
                'status' => $checkout['status'] ?? 'PAID',
                'amount' => $checkout['amount'] ?? 0,
                'paid_amount' => $checkout['paidAmount'] ?? $checkout['amount'] ?? 0,
                'payment_url' => $checkout['url'] ?? null,
                'receipt_url' => $checkout['receiptUrl'] ?? null,
                'dev_mode' => (bool) ($payload['devMode'] ?? false),
                'customer_name' => $customer['name'] ?? null,
                'customer_email' => $customer['email'] ?? null,
                'customer_tax_id' => $customer['taxId'] ?? null,
                'payload' => $payload,
                'completed_at' => $this->parseDate($checkout['updatedAt'] ?? null),
            ]
        );

        if (blank($resolvedPlanCode)) {
            return;
        }

        $attributes = [
            'user_id' => $user?->id ?? $existingSubscription?->user_id,
            'plan_code' => $resolvedPlanCode,
            'external_id' => $checkout['externalId'] ?? $event->external_id,
            'gateway_checkout_id' => $checkout['id'],
            'checkout_url' => $checkout['url'] ?? $existingSubscription?->checkout_url,
            'gateway_customer_id' => $checkout['customerId'] ?? $existingSubscription?->gateway_customer_id,
            'customer_name' => $customer['name'] ?? $existingSubscription?->customer_name,
            'customer_email' => $customer['email'] ?? $existingSubscription?->customer_email,
            'customer_tax_id' => $customer['taxId'] ?? $existingSubscription?->customer_tax_id,
            'amount' => $checkout['amount'] ?? $existingSubscription?->amount ?? 0,
            'currency' => $existingSubscription?->currency ?? 'BRL',
            'method' => $checkout['methods'][0] ?? $existingSubscription?->method,
            'frequency' => $existingSubscription?->frequency ?? 'MONTHLY',
            'status' => $checkout['status'] ?? 'PAID',
            'dev_mode' => (bool) ($payload['devMode'] ?? false),
            'starts_at' => $this->parseDate($checkout['updatedAt'] ?? $checkout['createdAt'] ?? null),
            'payload' => $payload,
        ];

        if ($existingSubscription) {
            $existingSubscription->update($attributes);
            $record = $existingSubscription->fresh();
        } else {
            $record = AbacatePaySubscription::query()->create($attributes);
        }

        $this->syncUserBillingAccess($user, $record, 'completed');
    }

    protected function handleSubscriptionUpsert(AbacatePayWebhookEvent $event, array $payload, string $kind): void
    {
        $subscription = $payload['data']['subscription'] ?? [];
        $customer = $payload['data']['customer'] ?? [];
        $payment = $payload['data']['payment'] ?? [];
        $checkout = $payload['data']['checkout'] ?? [];

        if (blank($subscription['id'] ?? null)) {
            return;
        }

        $externalId = $payment['externalId'] ?? $checkout['externalId'] ?? $event->external_id;
        $existingRecordQuery = AbacatePaySubscription::query()
            ->where('gateway_subscription_id', $subscription['id']);

        if (filled($externalId)) {
            $existingRecordQuery->orWhere('external_id', $externalId);
        }

        $existingRecord = $existingRecordQuery->latest('id')->first();
        $user = $this->resolveUser($customer['email'] ?? null) ?? $existingRecord?->user;
        $resolvedPlanCode = $this->resolvePlanCode($payload) ?? $existingRecord?->plan_code;
        $attributes = [
            'user_id' => $user?->id,
            'plan_code' => $resolvedPlanCode,
            'external_id' => $externalId,
            'gateway_subscription_id' => $subscription['id'],
            'gateway_customer_id' => $checkout['customerId'] ?? $existingRecord?->gateway_customer_id,
            'gateway_checkout_id' => $checkout['id'] ?? null,
            'checkout_url' => $checkout['url'] ?? $existingRecord?->checkout_url,
            'gateway_payment_id' => $payment['id'] ?? null,
            'customer_name' => $customer['name'] ?? null,
            'customer_email' => $customer['email'] ?? null,
            'customer_tax_id' => $customer['taxId'] ?? null,
            'amount' => $subscription['amount'] ?? $payment['amount'] ?? 0,
            'currency' => $subscription['currency'] ?? 'BRL',
            'method' => $subscription['method'] ?? ($payment['methods'][0] ?? null),
            'frequency' => $subscription['frequency'] ?? null,
            'status' => $subscription['status'] ?? strtoupper($kind),
            'dev_mode' => (bool) ($payload['devMode'] ?? false),
            'starts_at' => $this->parseDate($subscription['createdAt'] ?? null),
            'renewed_at' => $kind === 'renewed' ? now() : $existingRecord?->renewed_at,
            'cancelled_at' => $kind === 'cancelled'
                ? $this->parseDate($subscription['canceledAt'] ?? null) ?? now()
                : null,
            'payload' => $payload,
        ];

        if ($existingRecord) {
            $existingRecord->update($attributes);
            $record = $existingRecord->fresh();
        } else {
            $record = AbacatePaySubscription::query()->create($attributes);
        }

        if (! blank($payment['id'] ?? null)) {
            AbacatePayCharge::query()->updateOrCreate(
                ['gateway_charge_id' => $payment['id']],
                [
                    'user_id' => $record->user_id,
                    'external_id' => $payment['externalId'] ?? $event->external_id,
                    'charge_type' => 'subscription_payment',
                    'method' => $payment['methods'][0] ?? $subscription['method'] ?? null,
                    'status' => $payment['status'] ?? 'PAID',
                    'amount' => $payment['amount'] ?? $subscription['amount'] ?? 0,
                    'paid_amount' => $payment['paidAmount'] ?? $payment['amount'] ?? 0,
                    'receipt_url' => $payment['receiptUrl'] ?? null,
                    'dev_mode' => (bool) ($payload['devMode'] ?? false),
                    'customer_name' => $customer['name'] ?? null,
                    'customer_email' => $customer['email'] ?? null,
                    'customer_tax_id' => $customer['taxId'] ?? null,
                    'payload' => $payload,
                    'completed_at' => $this->parseDate($payment['updatedAt'] ?? null),
                ]
            );
        }

        $this->syncUserBillingAccess($user, $record, $kind);
    }

    protected function handlePaymentFailed(AbacatePayWebhookEvent $event, array $payload): void
    {
        $subscription = $payload['data']['subscription'] ?? [];
        $payment = $payload['data']['payment'] ?? [];
        $billing = $payload['data']['billing'] ?? [];
        $customer = $payload['data']['customer'] ?? ($billing['customer']['metadata'] ?? []);

        $externalId = $payment['externalId'] ?? $billing['externalId'] ?? $event->external_id;
        $gatewaySubscriptionId = $subscription['id'] ?? null;
        $gatewayPaymentId = $payment['id'] ?? $billing['id'] ?? null;

        $record = null;

        if (filled($gatewaySubscriptionId) || filled($externalId)) {
            $record = AbacatePaySubscription::query()
                ->where(function ($query) use ($gatewaySubscriptionId, $externalId) {
                    if (filled($gatewaySubscriptionId)) {
                        $query->where('gateway_subscription_id', $gatewaySubscriptionId);
                    }

                    if (filled($externalId)) {
                        $method = filled($gatewaySubscriptionId) ? 'orWhere' : 'where';
                        $query->{$method}('external_id', $externalId);
                    }
                })
                ->latest('id')
                ->first();
        }

        $user = $this->resolveUser($customer['email'] ?? null) ?? $record?->user;

        if ($record) {
            $record->forceFill([
                'gateway_payment_id' => $gatewayPaymentId ?: $record->gateway_payment_id,
                'status' => $subscription['status'] ?? $payment['status'] ?? $billing['status'] ?? 'PAYMENT_FAILED',
                'payload' => $payload,
            ])->save();
        }

        if ($user) {
            $this->safeNotify($user, new BillingPaymentFailedNotification(
                subscription: $record,
                reason: $payload['data']['error']['message'] ?? $payment['failureReason'] ?? $billing['failureReason'] ?? null,
            ));
        }
    }

    protected function resolveUser(?string $email): ?User
    {
        if (blank($email)) {
            return null;
        }

        return User::query()->where('email', $email)->first();
    }

    protected function parseDate(?string $value): ?Carbon
    {
        return filled($value) ? Carbon::parse($value) : null;
    }

    protected function resolvePendingSubscription(?string $gatewayCheckoutId, ?string $externalId): ?AbacatePaySubscription
    {
        if (blank($gatewayCheckoutId) && blank($externalId)) {
            return null;
        }

        return AbacatePaySubscription::query()
            ->where(function ($query) use ($gatewayCheckoutId, $externalId) {
                if (filled($gatewayCheckoutId)) {
                    $query->where('gateway_checkout_id', $gatewayCheckoutId);
                }

                if (filled($externalId)) {
                    $method = filled($gatewayCheckoutId) ? 'orWhere' : 'where';
                    $query->{$method}('external_id', $externalId);
                }
            })
            ->latest('id')
            ->first();
    }

    protected function resolvePlanCode(array $payload): ?string
    {
        return $payload['data']['checkout']['metadata']['plan_code']
            ?? $payload['data']['checkout']['products'][0]['externalId']
            ?? $payload['data']['billing']['metadata']['plan_code']
            ?? $payload['data']['billing']['products'][0]['externalId']
            ?? $payload['data']['payment']['metadata']['plan_code']
            ?? $payload['data']['subscription']['metadata']['plan_code']
            ?? null;
    }

    protected function syncUserBillingAccess(?User $user, AbacatePaySubscription $subscription, string $kind): void
    {
        if (! $user || blank($subscription->plan_code)) {
            return;
        }

        if (! $this->billingPlanService->find($subscription->plan_code)) {
            return;
        }

        $wasActivePaidPlan = $user->hasActivePaidPlan();
        $previousPlanCode = $user->billing_plan_code;
        $previousBillingStatus = $user->billing_plan_status;
        $accessEndsAt = $user->billing_access_ends_at instanceof Carbon
            ? $user->billing_access_ends_at->copy()
            : null;

        if (in_array($kind, ['completed', 'renewed'], true)) {
            $baseDate = $accessEndsAt && $accessEndsAt->isFuture()
                ? $accessEndsAt
                : now();

            $nextAccessEndsAt = match ($subscription->frequency) {
                'YEARLY' => $baseDate->copy()->addYear(),
                'WEEKLY' => $baseDate->copy()->addWeek(),
                default => $baseDate->copy()->addMonth(),
            };

            $user->forceFill([
                'abacatepay_customer_id' => $subscription->gateway_customer_id ?: $user->abacatepay_customer_id,
                'billing_plan_code' => $subscription->plan_code,
                'billing_plan_status' => $kind === 'renewed' ? 'renewed' : 'active',
                'billing_access_ends_at' => $nextAccessEndsAt,
            ])->save();

            if ($kind === 'renewed' || ! $wasActivePaidPlan || $previousPlanCode !== $subscription->plan_code) {
                $this->safeNotify($user, new BillingSubscriptionActivatedNotification(
                    subscription: $subscription,
                    accessEndsAt: $nextAccessEndsAt,
                    renewed: $kind === 'renewed',
                ));
            }

            return;
        }

        if ($kind === 'cancelled') {
            $user->forceFill([
                'billing_plan_code' => $subscription->plan_code,
                'billing_plan_status' => 'cancelled',
                'billing_access_ends_at' => now(),
            ])->save();

            if ($previousBillingStatus !== 'cancelled') {
                $this->safeNotify($user, new BillingSubscriptionCancelledNotification($subscription));
            }
        }
    }

    protected function safeNotify(User $user, mixed $notification): void
    {
        try {
            $user->notify($notification);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
