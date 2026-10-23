<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessVexaWebhook;
use App\Models\Meeting;
use App\Models\MeetingWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VexaWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $secret = (string) config('vexa.webhook_secret');
        $timestamp = (string) $request->header('X-Webhook-Timestamp', '');
        $signature = (string) $request->header('X-Webhook-Signature', '');
        $tolerance = max(30, (int) config('vexa.webhook_tolerance_seconds', 300));

        if ($secret === '' || ! ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > $tolerance) {
            return response()->json(['status' => 'unauthorized'], 401);
        }
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
        if (! hash_equals($expected, $signature)) {
            return response()->json(['status' => 'invalid_signature'], 401);
        }

        $payload = $request->json()->all();
        $eventType = (string) ($payload['event_type'] ?? $payload['type'] ?? $payload['event'] ?? '');
        if (! in_array($eventType, ['meeting.status_change', 'meeting.started', 'meeting.completed', 'bot.failed'], true)) {
            return response()->json(['status' => 'ignored']);
        }
        if ($eventType === 'meeting.status_change' && ! $this->isValidStatusChangeEnvelope($payload)) {
            return response()->json(['status' => 'invalid_event'], 422);
        }
        $platform = data_get($payload, 'data.meeting.platform') ?? data_get($payload, 'data.platform') ?? data_get($payload, 'meeting.platform');
        $nativeId = data_get($payload, 'data.meeting.native_meeting_id') ?? data_get($payload, 'data.native_meeting_id') ?? data_get($payload, 'meeting.native_meeting_id');
        $remoteId = data_get($payload, 'data.meeting.id') ?? data_get($payload, 'data.meeting_id') ?? data_get($payload, 'meeting.id');
        $meeting = null;
        if (is_string($platform) && is_string($nativeId) && $nativeId !== '') {
            $lookup = hash_hmac('sha256', $platform."\0".$nativeId, (string) config('app.key'));
            $matches = Meeting::query()->where('platform', $platform)->where('native_meeting_id_hash', $lookup)->limit(2)->get();
            if ($matches->count() === 1) $meeting = $matches->first();
        }
        if (! $meeting && (is_int($remoteId) || is_string($remoteId))) {
            $remoteHash = hash_hmac('sha256', (string) $remoteId, (string) config('app.key'));
            $meeting = Meeting::query()->where('vexa_meeting_id_hash', $remoteHash)->first();
        }
        if (! $meeting) {
            if ($eventType !== 'meeting.status_change') {
                return response()->json(['status' => 'unknown_meeting'], 404);
            }

            // This authenticated lifecycle event has no safe meeting correlation key.
            // Persist it idempotently for observability, but do not mutate any meeting.
            $eventId = $payload['event_id'] ?? $payload['id'] ?? null;
            $secretHash = hash_hmac('sha256', is_string($eventId) ? $eventId : $request->getContent(), $secret);
            try {
                MeetingWebhookEvent::query()->create([
                    'event_hash' => $secretHash,
                    'meeting_id' => null,
                    'event_type' => $eventType,
                    'payload' => $payload,
                    'received_at' => now(),
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                return response()->json(['status' => 'duplicate']);
            }
            return response()->json(['status' => 'accepted_unmatched'], 202);
        }

        $eventId = $payload['event_id'] ?? $payload['id'] ?? null;
        $secretHash = hash_hmac('sha256', is_string($eventId) ? $eventId : $request->getContent(), $secret);
        try {
            $event = MeetingWebhookEvent::query()->create([
                'event_hash' => $secretHash,
                'meeting_id' => $meeting->id,
                'event_type' => $eventType,
                'payload' => $payload,
                'received_at' => now(),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return response()->json(['status' => 'duplicate']);
        }

        ProcessVexaWebhook::dispatch($event->id);
        return response()->json(['status' => 'queued']);
    }

    private function isValidStatusChangeEnvelope(array $payload): bool
    {
        $meeting = data_get($payload, 'data.meeting');
        $change = data_get($payload, 'data.status_change');

        return is_string($payload['event_id'] ?? null)
            && is_string($payload['api_version'] ?? null)
            && is_string($payload['created_at'] ?? null)
            && strtotime($payload['created_at']) !== false
            && is_array($meeting)
            && is_array($change)
            && is_string($change['to'] ?? null)
            && is_string($change['timestamp'] ?? null);
    }
}
