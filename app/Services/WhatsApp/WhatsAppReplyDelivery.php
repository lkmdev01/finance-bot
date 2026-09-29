<?php

namespace App\Services\WhatsApp;

use App\Jobs\DeliverWhatsAppReply;
use App\Models\WhatsAppOutboundMessage;
use App\Services\BaileysService;
use Illuminate\Support\Facades\Log;

class WhatsAppReplyDelivery
{
    public function __construct(private readonly BaileysService $baileysService) {}

    public function send(string $recipientJid, string $body, ?int $userId = null): void
    {
        $outbound = WhatsAppOutboundMessage::create([
            'user_id' => $userId,
            'recipient_jid' => $recipientJid,
            'body' => $body,
        ]);

        if (! $this->attempt($outbound)) {
            try {
                DeliverWhatsAppReply::dispatch($outbound->id);
            } catch (\Throwable $error) {
                Log::error('Nao foi possivel enfileirar nova tentativa de resposta do WhatsApp', [
                    'outbound_message_id' => $outbound->id,
                    'error_type' => $error::class,
                ]);
            }
        }
    }

    public function attempt(WhatsAppOutboundMessage $outbound): bool
    {
        // Claim the reply atomically. A second worker must not send it while
        // another attempt is in flight. Stale "sending" rows need manual review:
        // the provider may have delivered the text before the worker stopped.
        $claimed = WhatsAppOutboundMessage::query()
            ->whereKey($outbound->id)
            ->where('status', 'pending')
            ->update(['status' => 'sending', 'updated_at' => now()]);

        if ($claimed === 0) {
            return true;
        }

        $outbound->refresh();
        $outbound->increment('attempts');

        try {
            $response = $this->baileysService->sendTextMessage($outbound->recipient_jid, $outbound->body);
            $outbound->last_http_status = $response->status();

            if ($response->successful()) {
                $outbound->status = 'delivered';
                $outbound->delivered_at = now();
                $outbound->save();

                return true;
            }

            // The gateway returns this specific 503 before calling Baileys.
            // Other server errors can happen after the provider accepted the text.
            if ($response->status() >= 500 && ! ($response->status() === 503 && $response->json('error') === 'WhatsApp não está conectado')) {
                $outbound->status = 'needs_review';
                $outbound->save();

                return true;
            }
        } catch (\Throwable $error) {
            Log::warning('Falha de transporte ao enviar resposta do WhatsApp', [
                'outbound_message_id' => $outbound->id,
                'error_type' => $error::class,
            ]);

            $outbound->status = 'needs_review';
            $outbound->save();

            return true;
        }

        $outbound->status = 'pending';
        $outbound->save();

        Log::warning('Resposta do WhatsApp pendente de nova tentativa', [
            'outbound_message_id' => $outbound->id,
            'attempts' => $outbound->attempts,
            'http_status' => $outbound->last_http_status,
        ]);

        return false;
    }
}
