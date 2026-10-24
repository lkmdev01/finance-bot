<?php

namespace App\Jobs;

use App\Models\Meeting;
use App\Services\Meetings\VexaApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class StopMeetingBot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 45;

    public function __construct(public readonly int $meetingId) {}

    public function backoff(): array
    {
        return [15, 30, 60, 120];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('vexa-stop-meeting-'.$this->meetingId))->releaseAfter(30)->expireAfter(120)];
    }

    public function handle(VexaApiClient $vexa): void
    {
        $meeting = Meeting::find($this->meetingId);
        if (! $meeting || $meeting->status !== 'stopping' || blank($meeting->native_meeting_id)) {
            return;
        }

        $vexa->stopBot($meeting->platform, (string) $meeting->native_meeting_id);

        Log::info('Solicitação de parada aceita pelo Vexa; aguardando conclusão da gravação', [
            'meeting_id' => $meeting->id,
            'reason' => $meeting->stop_reason ?: 'unknown',
        ]);

        PollMeetingStatus::dispatch($meeting->id)->delay(now()->addSeconds(10));
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('Solicitação de parada do bot Vexa falhou; reconciliação tentará novamente', [
            'meeting_id' => $this->meetingId,
            'exception' => $exception::class,
        ]);
    }
}
