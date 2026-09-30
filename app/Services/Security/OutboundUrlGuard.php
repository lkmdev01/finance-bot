<?php

namespace App\Services\Security;

use InvalidArgumentException;

class OutboundUrlGuard
{
    /**
     * Validate an outbound HTTPS URL and return a public IP to pin during delivery.
     */
    public function assertAllowed(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || blank($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw new InvalidArgumentException('Use uma URL HTTPS pública, sem credenciais embutidas.');
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new InvalidArgumentException('O destino do webhook não pode ser local ou privado.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new InvalidArgumentException('O destino do webhook deve usar um endereço IPv4 público.');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            ? [$host]
            : $this->resolve($host);

        if ($addresses === []) {
            throw new InvalidArgumentException('Não foi possível resolver o domínio do webhook.');
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicIp($address)) {
                throw new InvalidArgumentException('O destino do webhook não pode apontar para uma rede local ou privada.');
            }
        }

        return $addresses[0];
    }

    /** @return array<int, string> */
    protected function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A);

        if (! is_array($records)) {
            return [];
        }

        return collect($records)
            ->map(fn (array $record) => $record['ip'] ?? null)
            ->filter(fn ($address) => is_string($address))
            ->unique()
            ->values()
            ->all();
    }

    private function isPublicIp(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
