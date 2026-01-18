<?php

namespace App\Services;

use App\Models\User;
use App\Models\Webhook;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    public function dispatch(string $event, User $user, array $data): void
    {
        $webhooks = $user->webhooks()
            ->where('is_active', true)
            ->get();

        foreach ($webhooks as $webhook) {
            if ($webhook->shouldTrigger($event)) {
                $this->sendWebhook($webhook, $event, $data);
            }
        }
    }

    protected function sendWebhook(Webhook $webhook, string $event, array $data): void
    {
        try {
            $payload = [
                'event' => $event,
                'timestamp' => now()->toIso8601String(),
                'data' => $data,
            ];

            // Adicionar assinatura se secret estiver configurado
            if ($webhook->secret) {
                $payload['signature'] = hash_hmac('sha256', json_encode($payload), $webhook->secret);
            }

            $response = \Illuminate\Support\Facades\Http::timeout(5)
                ->post($webhook->url, $payload);

            if ($response->successful()) {
                $webhook->recordSuccess();
            } else {
                $webhook->recordFailure();
            }
        } catch (\Exception $e) {
            $webhook->recordFailure();
            Log::error('Webhook failed', [
                'webhook_id' => $webhook->id,
                'url' => $webhook->url,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

}
