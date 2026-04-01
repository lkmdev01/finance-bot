<?php

namespace App\Services;

use App\Models\AbacatePayCharge;
use App\Models\AbacatePaySubscription;
use App\Models\AbacatePayWebhookEvent;
use App\Models\User;
use Carbon\Carbon;
use App\Services\BillingPlanService;

class AbacatePayWebhookProcessor
{
    public function __construct(
        private readonly BillingPlanService $billingPlanService,
    ) {}

    public function process(AbacatePayWebhookEvent $event, array $payload): void
    {
        $eventName = $payload['event'] ?? 'unknown';

        match ($eventName) {
            'transparent.completed' => $this->handleTransparentCompleted($event, $payload),
            'checkout.completed' => $this->handleCheckoutCompleted($event, $payload),
            'subscription.completed' => $this->handleSubscriptionUpsert($event, $payload, 'completed'),
            'subscription.renewed' => $this->handleSubscriptionUpsert($event, $payload, 'renewed'),
            'subscription.cancelled' => $this->handleSubscriptionUpsert($event, $payload, 'cancelled'),
            default => null,
        };
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

        if (blank($checkout['id'] ?? null)) {
            return;
        }

        $user = $this->resolveUser($customer['email'] ?? null);

        AbacatePayCharge::query()->updateOrCreate(
            ['gateway_charge_id' => $checkout['id']],
            [
                'user_id' => $user?->id,
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

        $user = $this->resolveUser($customer['email'] ?? null);
        $externalId = $payment['externalId'] ?? $checkout['externalId'] ?? $event->external_id;
        $existingRecordQuery = AbacatePaySubscription::query()
            ->where('gateway_subscription_id', $subscription['id']);

        if (filled($externalId)) {
            $existingRecordQuery->orWhere('external_id', $externalId);
        }

        $existingRecord = $existingRecordQuery->latest('id')->first();
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

    protected function resolvePlanCode(array $payload): ?string
    {
        return $payload['data']['checkout']['metadata']['plan_code']
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

            return;
        }

        if ($kind === 'cancelled') {
            $user->forceFill([
                'billing_plan_code' => $subscription->plan_code,
                'billing_plan_status' => 'cancelled',
            ])->save();
        }
    }
}
