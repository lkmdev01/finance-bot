<?php

namespace App\Services\OpenFinance;

use InvalidArgumentException;

class OpenFinanceManager
{
    public function __construct(
        private readonly PluggyService $pluggy,
    ) {}

    public function provider(?string $provider = null): OpenFinanceProvider
    {
        $provider = $provider ?: (string) config('openfinance.provider', 'pluggy');

        return match ($provider) {
            'pluggy' => $this->pluggy,
            default => throw new InvalidArgumentException("Open Finance provider [{$provider}] nao suportado."),
        };
    }
}
