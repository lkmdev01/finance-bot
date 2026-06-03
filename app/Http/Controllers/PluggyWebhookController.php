<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessPluggyWebhook;
use App\Models\PluggyWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PluggyWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        $eventId = (string) ($payload['eventId'] ?? '');
        $eventName = (string) ($payload['event'] ?? 'unknown');

        if ($eventId === '') {
            return response()->json(['received' => false, 'error' => 'missing_event_id'], 422);
        }

        $existing = PluggyWebhookEvent::query()->where('event_id', $eventId)->first();

        if ($existing && in_array($existing->status, ['received', 'processing', 'processed'], true)) {
            return response()->json(['received' => true, 'status' => 'duplicate']);
        }

        $event = PluggyWebhookEvent::updateOrCreate(
            ['event_id' => $eventId],
            [
                'event_name' => $eventName,
                'item_id' => $payload['itemId'] ?? null,
                'client_user_id' => $payload['clientUserId'] ?? null,
                'status' => 'received',
                'payload' => $payload,
                'received_at' => now(),
                'processed_at' => null,
                'error_message' => null,
            ],
        );

        Log::info('Webhook Pluggy recebido', [
            'event' => $eventName,
            'event_id' => $eventId,
            'item_id' => $payload['itemId'] ?? null,
        ]);

        ProcessPluggyWebhook::dispatch($event->id);

        return response()->json(['received' => true]);
    }
}
