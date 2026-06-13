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
            throw new InvalidArgumentException("Plano [{$code}] nao encontrado.");
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

        if (in_array($feature, $plan['features'], true)) {
            if ($plan['price_cents'] === 0) {
                return true;
            }

            return $user->hasActivePaidPlan();
        }

        if ($user->hasActiveTrial()) {
            return in_array($feature, (array) config('billing.premium_features', []), true);
        }

        return false;
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
                ? "Seu teste gratis esta ativo ate {$date}."
                : 'Seu teste gratis esta ativo.';
        }

        return (string) config('billing.trial_expired_message', 'Seu teste gratuito terminou. Para continuar registrando novas informacoes, ative um plano.');
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
            $missing[] = 'Numero de WhatsApp';
        }

        if (blank($user->tax_id)) {
            $missing[] = 'CPF ou CNPJ';
        } elseif (! BrazilTaxId::isValid($user->tax_id)) {
            $missing[] = 'CPF ou CNPJ valido';
        }

        return $missing;
    }

    protected function decorate(array $plan): array
    {
        $priceCents = (int) ($plan['price_cents'] ?? 0);
        $flow = (string) ($plan['checkout_flow'] ?? 'checkout');
        $frequency = strtoupper((string) ($plan['frequency'] ?? 'NONE'));

        $plan['formatted_price'] = $priceCents === 0
            ? 'Gratis'
            : 'R$ '.number_format($priceCents / 100, 2, ',', '.');

        $plan['frequency_label'] = match ($frequency) {
            'MONTHLY' => 'mensal',
            'YEARLY' => 'anual',
            default => 'livre',
        };

        $plan['billing_mode'] = $flow === 'subscription' ? 'recurring' : 'one_time';
        $plan['billing_mode_label'] = $flow === 'subscription'
            ? 'Renovacao automatica'
            : 'Pagamento unico';

        $plan['billing_mode_description'] = match (true) {
            $priceCents === 0 => 'Sem cobranca.',
            $flow === 'subscription' && $frequency === 'MONTHLY' => 'Renova automaticamente todo mes no cartao.',
            $flow === 'subscription' && $frequency === 'YEARLY' => 'Renova automaticamente a cada ano no cartao.',
            $frequency === 'MONTHLY' => 'Libera 30 dias de acesso sem renovacao automatica.',
            $frequency === 'YEARLY' => 'Libera 12 meses de acesso sem renovacao automatica.',
            default => 'Acesso liberado conforme o periodo do plano.',
        };

        return $plan;
    }
}
