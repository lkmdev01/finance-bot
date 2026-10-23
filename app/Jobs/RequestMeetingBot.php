<?php

namespace App\Jobs;

use App\Models\Meeting;
use App\Services\Meetings\VexaApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RequestMeetingBot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly int $meetingId) {}

    public function backoff(): array { return [15, 30, 60, 120]; }

    public function handle(VexaApiClient $vexa): void
    {
        $meeting = Meeting::find($this->meetingId);
        if (! $meeting || ! in_array($meeting->status, ['queued', 'requesting'], true)) return;
        $meeting->update(['status' => 'requesting']);
        if (filled(config('vexa.webhook_url')) && filled(config('vexa.webhook_secret'))) {
            try {
                $vexa->configureWebhook((string) config('vexa.webhook_url'), (string) config('vexa.webhook_secret'));
            } catch (Throwable $error) {
                // REST polling remains available if webhook setup/delivery is unavailable.
                Log::warning('Configuração de webhook Vexa falhou; acompanhamento seguirá por polling', [
                    'meeting_id' => $meeting->id,
                    'exception' => $error::class,
                ]);
            }
        }
        $result = $vexa->sendBot($meeting->meeting_url);
        $vexaId = $result['id'] ?? $result['meeting_id'] ?? data_get($result, 'meeting.id');
        $meeting->update([
            'vexa_meeting_id' => $vexaId === null ? null : (string) $vexaId,
            'vexa_meeting_id_hash' => $vexaId === null ? null : hash_hmac('sha256', (string) $vexaId, (string) config('app.key')),
            'status' => (string) (data_get($result, 'status') ?: data_get($result, 'meeting.status') ?: 'requested'),
            'started_at' => now(),
        ]);
        PollMeetingStatus::dispatch($meeting->id)->delay(now()->addSeconds(max(15, (int) config('vexa.poll_seconds', 60))));
    }

    public function failed(Throwable $exception): void
    {
        $retentionDays = min(3650, max(1, (int) config('vexa.retention_days', 90)));
        Meeting::whereKey($this->meetingId)->whereNotIn('status', ['completed', 'reported'])->update([
            'status' => 'failed', 'failure_code' => 'vexa_bot_request_failed',
            'expires_at' => now()->addDays($retentionDays),
        ]);
        Log::warning('Solicitação de bot ao Vexa falhou', ['meeting_id' => $this->meetingId, 'exception' => $exception::class]);
    }
}
