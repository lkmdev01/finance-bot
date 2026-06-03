<?php

namespace App\Jobs;

use App\Models\PluggyWebhookEvent;
use App\Services\OpenFinance\PluggyWebhookProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessPluggyWebhook implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $webhookEventId,
    ) {}

    public function handle(PluggyWebhookProcessor $processor): void
    {
        $event = PluggyWebhookEvent::find($this->webhookEventId);

        if (! $event || $event->status === 'processed') {
            return;
        }

        $processor->process($event);
    }
}
