<?php

namespace App\Jobs;

use App\Models\Meeting;
use App\Models\MeetingWebhookEvent;
use App\Services\Meetings\MeetingTranscriptionTaskService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessVexaWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public function __construct(public readonly int $eventId) {}
    public function backoff(): array { return [15, 30, 60, 120]; }

    public function handle(MeetingTranscriptionTaskService $tasks): void
    {
        $event = MeetingWebhookEvent::find($this->eventId);
        $meeting = $event?->meeting;
        if (! $event || ! $meeting) return;

        $eventData = $event->payload ?? [];
        $status = data_get($eventData, 'data.meeting.status')
            ?? data_get($eventData, 'data.status')
            ?? data_get($eventData, 'meeting.status');
        $type = $event->event_type;
        if ($type === 'meeting.started') $status = 'active';
        if ($type === 'meeting.completed') $status = 'completed';
        if ($type === 'bot.failed') $status = 'failed';
        if (is_string($status) && $status !== '') {
            $meeting->update([
                'status' => $status,
                'failure_code' => $status === 'failed' ? 'vexa_bot_failed' : null,
            ]);
        }

        if ($type === 'meeting.completed') {
            $retentionDays = min(3650, max(1, (int) config('vexa.retention_days', 90)));
            $meeting->update([
                'status' => 'awaiting_transcription',
                'completed_at' => $meeting->completed_at ?? now(),
                'expires_at' => $meeting->expires_at ?? now()->addDays($retentionDays),
            ]);
            $tasks->createFor($meeting);
        }
        if ($type === 'bot.failed' || $status === 'failed') {
            $retentionDays = min(3650, max(1, (int) config('vexa.retention_days', 90)));
            $meeting->update(['expires_at' => $meeting->expires_at ?? now()->addDays($retentionDays)]);
            Log::warning('Bot Vexa encerrou com falha', ['meeting_id' => $meeting->id]);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('Processamento de webhook Vexa falhou', ['event_id' => $this->eventId, 'exception' => $exception::class]);
    }
}
