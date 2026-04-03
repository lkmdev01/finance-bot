<?php

namespace App\Services;

use App\Models\User;
use App\Support\BrazilTaxId;
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

    public function userCanCreateRecords(User $user): bool
    {
        return $user->hasWritableFinancialAccess();
    }

    public function writeAccessMessage(User $user): string
    {
        if ($user->hasActiveTrial()) {
            $date = $user->trial_ends_at?->format('d/m/Y');

            return $date
                ? "Seu teste grátis está ativo até {$date}."
                : 'Seu teste grátis está ativo.';
        }

        return (string) config('billing.trial_expired_message', 'Seu teste gratuito terminou. Para continuar registrando novas informações, ative um plano.');
    }

    public function missingBillingRequirements(User $user): array
    {
        $missing = [];

        if (blank($user->name)) {
            $missing[] = 'Nome completo';
        }

        if (blank($user->email)) {
            $missing[] = 'E-mail';
        }

        if (blank($user->phone_number)) {
            $missing[] = 'Número de WhatsApp';
        }

        if (blank($user->tax_id)) {
            $missing[] = 'CPF ou CNPJ';
        } elseif (! BrazilTaxId::isValid($user->tax_id)) {
            $missing[] = 'CPF ou CNPJ válido';
        }

        return $missing;
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
