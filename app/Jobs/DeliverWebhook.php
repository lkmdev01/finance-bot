<?php

namespace App\Jobs;

use App\Models\Webhook;
use App\Services\Security\OutboundUrlGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600];

    public function __construct(
        public readonly int $webhookId,
        public readonly string $event,
        public readonly array $data,
    ) {}

    public function handle(OutboundUrlGuard $guard): void
    {
        $webhook = Webhook::query()->find($this->webhookId);

        if (! $webhook || ! $webhook->shouldTrigger($this->event)) {
            return;
        }

        try {
            $resolvedIp = $guard->assertAllowed($webhook->url);
            $payload = [
                'event' => $this->event,
                'timestamp' => now()->toIso8601String(),
                'data' => $this->data,
            ];

            if ($webhook->secret) {
                $payload['signature'] = hash_hmac('sha256', json_encode($payload), $webhook->secret);
            }

            $parts = parse_url($webhook->url);
            $options = ['allow_redirects' => false];

            if (filter_var((string) ($parts['host'] ?? ''), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                if (! defined('CURLOPT_RESOLVE')) {
                    throw new \RuntimeException('A extensão cURL é necessária para fixar o DNS de webhooks.');
                }

                $port = (int) ($parts['port'] ?? 443);
                $options['curl'] = [CURLOPT_RESOLVE => ["{$parts['host']}:{$port}:{$resolvedIp}"]];
            }

            $response = Http::timeout(5)
                ->connectTimeout(3)
                ->withOptions($options)
                ->post($webhook->url, $payload);

            if (! $response->successful()) {
                throw new \RuntimeException("Webhook retornou HTTP {$response->status()}.");
            }

            $webhook->recordSuccess();
        } catch (Throwable $exception) {
            $webhook->recordFailure();

            Log::warning('Webhook delivery failed', [
                'webhook_id' => $webhook->id,
                'event' => $this->event,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
