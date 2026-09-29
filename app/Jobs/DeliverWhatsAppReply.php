<?php

namespace App\Jobs;

use App\Models\WhatsAppOutboundMessage;
use App\Services\WhatsApp\WhatsAppReplyDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverWhatsAppReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $outboundMessageId) {}

    public function backoff(): array
    {
        return [30, 60, 120, 300];
    }

    public function handle(WhatsAppReplyDelivery $delivery): void
    {
        $outbound = WhatsAppOutboundMessage::find($this->outboundMessageId);

        if ($outbound === null || $outbound->status !== 'pending') {
            return;
        }

        if (! $delivery->attempt($outbound)) {
            throw new \RuntimeException('Falha ao entregar resposta do WhatsApp; nova tentativa agendada.');
        }
    }

    public function failed(): void
    {
        WhatsAppOutboundMessage::query()
            ->whereKey($this->outboundMessageId)
            ->where('status', 'pending')
            ->update(['status' => 'failed']);
    }
}
