<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class BillingPlanService
{
    public function all(): Collection
    {
        return collect(config('billing.plans', []))
            ->map(fn (array $plan) => $this->decorate($plan));
    }

    public function find(string $code): ?array
    {
        $plan = config("billing.plans.{$code}");

        return is_array($plan) ? $this->decorate($plan) : null;
    }

    public function findOrFail(string $code): array
    {
        $plan = $this->find($code);

        if (! $plan) {
            throw new InvalidArgumentException("Plano [{$code}] não encontrado.");
        }

        return $plan;
    }

    public function defaultPlan(): array
    {
        return $this->findOrFail((string) config('billing.default_plan', 'starter'));
    }

    public function userPlan(User $user): array
    {
        return $this->find($user->billing_plan_code ?: $this->defaultPlan()['code'])
            ?? $this->defaultPlan();
    }

    public function userHasFeature(User $user, string $feature): bool
    {
        $plan = $this->userPlan($user);

        if (! in_array($feature, $plan['features'], true)) {
            return false;
        }

        if ($plan['price_cents'] === 0) {
            return true;
        }

        return $user->hasActivePaidPlan();
    }

    protected function decorate(array $plan): array
    {
        $priceCents = (int) ($plan['price_cents'] ?? 0);

        $plan['formatted_price'] = $priceCents === 0
            ? 'Grátis'
            : 'R$ '.number_format($priceCents / 100, 2, ',', '.');

        return $plan;
    }
}
