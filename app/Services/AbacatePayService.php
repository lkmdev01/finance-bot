<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class AbacatePayService
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $legacyBaseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeout = 15,
    ) {}

    public function createTransparentCharge(array $payload): array
    {
        return $this->post('/transparents/create', $payload);
    }

    public function createCheckout(array $payload): array
    {
        return $this->post('/checkouts/create', $payload);
    }

    public function createCustomer(array $payload): array
    {
        return $this->legacyPost('/customer/create', $payload);
    }

    public function createSubscriptionCheckout(array $payload): array
    {
        return $this->legacyPost('/billing/create', $payload);
    }

    public function getCheckout(array $query): array
    {
        return $this->get('/checkouts/get', $query);
    }

    public function getTransparent(array $query): array
    {
        return $this->get('/transparents/get', $query);
    }

    public function verifyWebhookSignature(string $rawBody, ?string $signature): bool
    {
        $publicKey = config('abacatepay.public_hmac_key');

        if (blank($publicKey) || blank($signature)) {
            return false;
        }

        $expectedSignature = base64_encode(
            hash_hmac('sha256', $rawBody, $publicKey, true)
        );

        return hash_equals($expectedSignature, $signature);
    }

    public function usesConfiguredCredentials(): bool
    {
        return filled($this->apiKey);
    }

    protected function get(string $path, array $query = []): array
    {
        return $this->request($this->baseUrl)->get($path, $query)->json();
    }

    protected function post(string $path, array $payload = []): array
    {
        return $this->request($this->baseUrl)->post($path, $payload)->json();
    }

    protected function legacyPost(string $path, array $payload = []): array
    {
        return $this->request($this->legacyBaseUrl)->post($path, $payload)->json();
    }

    protected function request(string $baseUrl): PendingRequest
    {
        if (blank($this->apiKey)) {
            throw new InvalidArgumentException('ABACATEPAY_API_KEY nao configurada.');
        }

        return Http::baseUrl(rtrim($baseUrl, '/'))
            ->acceptJson()
            ->asJson()
            ->withToken($this->apiKey)
            ->timeout($this->timeout);
    }
}
