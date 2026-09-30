<?php

namespace App\Services;

use App\Jobs\DeliverWebhook;
use App\Models\User;

class WebhookService
{
    public function dispatch(string $event, User $user, array $data): void
    {
        $webhooks = $user->webhooks()
            ->where('is_active', true)
            ->get();

        foreach ($webhooks as $webhook) {
            if ($webhook->shouldTrigger($event)) {
                DeliverWebhook::dispatch($webhook->id, $event, $data)->afterCommit();
            }
        }
    }
}
