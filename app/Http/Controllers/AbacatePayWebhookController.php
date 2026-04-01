<?php

namespace App\Http\Controllers;

use App\Models\AbacatePayWebhookEvent;
use App\Services\AbacatePayService;
use App\Services\AbacatePayWebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AbacatePayWebhookController extends Controller
{
    public function __construct(
        private readonly AbacatePayService $abacatePayService,
        private readonly AbacatePayWebhookProcessor $abacatePayWebhookProcessor,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $expectedSecret = config('abacatepay.webhook_secret');
        $receivedSecret = $request->query('webhookSecret');

        if (blank($expectedSecret) || ! hash_equals((string) $expectedSecret, (string) $receivedSecret)) {
            Log::warning('Webhook da AbacatePay recebido com secret invalido.', [
                'received_secret_present' => filled($receivedSecret),
            ]);

            return response()->json(['success' => false, 'error' => 'unauthorized'], 401);
        }

        $rawBody = $request->getContent();
        $signature = $request->header('X-Webhook-Signature');

        if (! $this->abacatePayService->verifyWebhookSignature($rawBody, $signature)) {
            Log::warning('Webhook da AbacatePay recebido com assinatura invalida.');

            return response()->json(['success' => false, 'error' => 'invalid_signature'], 401);
        }

        $payload = $request->json()->all();
        $eventId = $payload['id'] ?? null;

        if (blank($eventId)) {
            return response()->json(['success' => false, 'error' => 'missing_event_id'], 422);
        }

        $existingEvent = AbacatePayWebhookEvent::query()
            ->where('external_id', $eventId)
            ->first();

        if ($existingEvent) {
            if ($existingEvent->status === 'failed') {
                $event = tap($existingEvent)->update([
                    'event_name' => $payload['event'] ?? $existingEvent->event_name,
                    'api_version' => $payload['apiVersion'] ?? $existingEvent->api_version,
                    'dev_mode' => (bool) ($payload['devMode'] ?? $existingEvent->dev_mode),
                    'status' => 'received',
                    'payload' => $payload,
                    'received_at' => now(),
                    'processed_at' => null,
                    'error_message' => null,
                ]);

                return $this->processEvent($event->fresh(), $payload);
            }

            return response()->json([
                'success' => true,
                'status' => 'already_processed',
                'event' => $existingEvent->event_name,
            ]);
        }

        $event = AbacatePayWebhookEvent::create([
            'external_id' => $eventId,
            'event_name' => $payload['event'] ?? 'unknown',
            'api_version' => $payload['apiVersion'] ?? null,
            'dev_mode' => (bool) ($payload['devMode'] ?? false),
            'status' => 'received',
            'payload' => $payload,
            'received_at' => now(),
        ]);

        return $this->processEvent($event, $payload);
    }

    protected function processEvent(AbacatePayWebhookEvent $event, array $payload): JsonResponse
    {
        try {
            $this->abacatePayWebhookProcessor->process($event, $payload);

            $event->update([
                'status' => 'processed',
                'processed_at' => now(),
            ]);

            Log::info('Webhook da AbacatePay processado.', [
                'event_id' => $event->external_id,
                'event_name' => $event->event_name,
            ]);
        } catch (\Throwable $exception) {
            $event->update([
                'status' => 'failed',
                'processed_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);

            report($exception);

            return response()->json([
                'success' => false,
                'error' => 'processing_failed',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'status' => 'processed',
            'event' => $event->event_name,
        ]);
    }
}
