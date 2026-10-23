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

class PollMeetingStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 45;

    public function __construct(public readonly int $meetingId, public readonly int $pollCount = 0) {}
    public function backoff(): array { return [30, 60, 120]; }

    public function handle(VexaApiClient $vexa): void
    {
        $meeting = Meeting::find($this->meetingId);
        if (! $meeting || in_array($meeting->status, ['completed', 'reported', 'failed'], true)) return;
        if ($this->pollCount >= 240) {
            $meeting->update(['status' => 'needs_review', 'failure_code' => 'poll_timeout']);
            return;
        }
        $payload = $vexa->meeting($meeting->platform, $meeting->native_meeting_id);
        $remoteStatus = (string) (data_get($payload, 'status') ?: data_get($payload, 'meeting.status') ?: $meeting->status);
        $meeting->update(['status' => $remoteStatus]);

        if ($remoteStatus === 'completed') {
            AnalyzeMeetingTranscript::dispatch($meeting->id);
            return;
        }
        if ($remoteStatus === 'failed') {
            $meeting->update(['failure_code' => 'vexa_meeting_failed']);
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
