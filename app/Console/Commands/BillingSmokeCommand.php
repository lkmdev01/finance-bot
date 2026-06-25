<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BillingSmokeCommand extends Command
{
    protected $signature = 'billing:smoke
        {--json : Return machine-readable output}
        {--allow-missing-products : Do not fail when product IDs are not configured}';

    protected $description = 'Validate critical billing configuration for AbacatePay subscription checkout.';

    public function handle(): int
    {
        $checks = $this->checks();
        $failed = collect($checks)->contains(fn (array $check) => $check['status'] === 'fail');

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => ! $failed,
                'checks' => $checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $this->components->info('Billing smoke check');

        foreach ($checks as $check) {
            $prefix = $check['status'] === 'pass' ? '[OK]' : '[FAIL]';

            $this->line("{$prefix} {$check['name']}: {$check['message']}");
        }

        if ($failed) {
            $this->components->error('Billing smoke failed. Corrija a configuracao antes do deploy.');

            return self::FAILURE;
        }

        $this->components->info('Billing smoke passed.');

        return self::SUCCESS;
    }

    private function checks(): array
    {
        $subscriptionMethods = array_values((array) config('billing.subscription_methods', []));
        $plans = (array) config('billing.plans', []);
        $allowMissingProducts = (bool) $this->option('allow-missing-products');

        $checks = [
            $this->check(
                'subscription_methods.card_only',
                $subscriptionMethods === ['CARD'],
                'assinaturas devem usar apenas CARD para evitar PIX Automatico indisponivel'
            ),
        ];

        foreach (['pro_monthly' => 'MONTHLY', 'pro_yearly' => 'YEARLY'] as $planCode => $frequency) {
            $plan = $plans[$planCode] ?? [];

            $checks[] = $this->check(
                "{$planCode}.exists",
                $plan !== [],
                "plano {$planCode} existe"
            );

            $checks[] = $this->check(
                "{$planCode}.flow",
                ($plan['checkout_flow'] ?? null) === 'subscription',
                "plano {$planCode} usa checkout recorrente"
            );

            $checks[] = $this->check(
                "{$planCode}.frequency",
                ($plan['frequency'] ?? null) === $frequency,
                "plano {$planCode} tem cycle/frequencia {$frequency}"
            );

            $checks[] = $this->check(
                "{$planCode}.product_id",
                $allowMissingProducts || filled($plan['product_id'] ?? null),
                "plano {$planCode} tem product_id da AbacatePay configurado"
            );
        }

        return $checks;
    }

    private function check(string $name, bool $passes, string $message): array
    {
        return [
            'name' => $name,
            'status' => $passes ? 'pass' : 'fail',
            'message' => $message,
        ];
    }
}
