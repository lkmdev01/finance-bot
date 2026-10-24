<?php

namespace App\Jobs;

use App\Models\Meeting;
use App\Services\Meetings\VexaApiClient;
use App\Services\Meetings\MeetingTranscriptionTaskService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class PollMeetingStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 45;

    public function __construct(public readonly int $meetingId, public readonly int $pollCount = 0) {}
    public function backoff(): array { return [30, 60, 120]; }

    public function handle(VexaApiClient $vexa, MeetingTranscriptionTaskService $tasks): void
    {
        $meeting = Meeting::find($this->meetingId);
        if (! $meeting || in_array($meeting->status, ['reported', 'failed', 'needs_review', 'awaiting_transcription', 'transcribing', 'analyzing'], true)) return;
        if ($this->pollCount >= 240) {
            if (in_array($meeting->status, ['active', 'stopping'], true)) {
                self::dispatch($meeting->id, $this->pollCount + 1)->delay(now()->addMinutes(5));
                return;
            }

            $retentionDays = min(3650, max(1, (int) config('vexa.retention_days', 90)));
            $meeting->update([
                'status' => 'needs_review', 'failure_code' => 'poll_timeout',
                'expires_at' => $meeting->expires_at ?? now()->addDays($retentionDays),
            ]);
            return;
        }
        if (blank($meeting->vexa_meeting_id)) {
            self::dispatch($meeting->id, $this->pollCount + 1)
                ->delay(now()->addSeconds(max(15, (int) config('vexa.poll_seconds', 60))));
            return;
        }
        $payload = $vexa->meeting($meeting->vexa_meeting_id);
        $remoteStatus = (string) (data_get($payload, 'status') ?: data_get($payload, 'meeting.status') ?: $meeting->status);
        if ($meeting->status === 'stopping' && ! in_array($remoteStatus, ['completed', 'failed'], true)) {
            // A stop request is asynchronous; a live status must not undo the local stop intent.
            self::dispatch($meeting->id, $this->pollCount + 1)
                ->delay(now()->addSeconds(max(15, (int) config('vexa.poll_seconds', 60))));

            return;
        }

        $attributes = ['status' => $remoteStatus];
        if ($remoteStatus === 'active' && ! $meeting->active_since) {
            $attributes['active_since'] = data_get($payload, 'start_time')
                ?? data_get($payload, 'meeting.start_time')
                ?? now();
        }
        $meeting->update($attributes);

        if ($remoteStatus === 'completed') {
            $retentionDays = min(3650, max(1, (int) config('vexa.retention_days', 90)));
            $meeting->update([
                'status' => 'awaiting_transcription',
                'completed_at' => $meeting->completed_at ?? now(),
                'expires_at' => $meeting->expires_at ?? now()->addDays($retentionDays),
            ]);
            $tasks->createFor($meeting);
            return;
        }
        if ($remoteStatus === 'failed') {
            $retentionDays = min(3650, max(1, (int) config('vexa.retention_days', 90)));
            $meeting->update([
                'failure_code' => 'vexa_meeting_failed',
                'expires_at' => $meeting->expires_at ?? now()->addDays($retentionDays),
            ]);
            return;
        }

        self::dispatch($meeting->id, $this->pollCount + 1)
            ->delay(now()->addSeconds(max(15, (int) config('vexa.poll_seconds', 60))));
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('Consulta de estado Vexa falhou', ['meeting_id' => $this->meetingId, 'exception' => $exception::class]);
        $meeting = Meeting::find($this->meetingId);
        if ($meeting && ! in_array($meeting->status, ['completed', 'reported', 'failed'], true)) {
            self::dispatch($meeting->id, $this->pollCount + 1)->delay(now()->addMinutes(2));
        }
    }
}
