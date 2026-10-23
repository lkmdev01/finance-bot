<?php

namespace App\Jobs;

use App\Models\Meeting;
use App\Services\Meetings\MeetingAnalysisService;
use App\Services\WhatsApp\WhatsAppReplyDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeMeetingTranscript implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 180;
    public function __construct(public readonly int $meetingId) {}
    public function backoff(): array { return [60, 180]; }
    public function middleware(): array { return [(new WithoutOverlapping('meeting-analysis-'.$this->meetingId))->releaseAfter(30)->expireAfter(240)]; }

    public function handle(MeetingAnalysisService $analysis, WhatsAppReplyDelivery $delivery): void
    {
        $meeting = Meeting::find($this->meetingId);
        if (! $meeting || $meeting->reported_at) return;
        $meeting->update(['status' => 'analyzing']);
        $segments = $meeting->transcript_segments;
        if (! is_array($segments) || $segments === []) {
            throw new \RuntimeException('Transcrição posterior ausente ou inválida.');
        }
        $summary = $analysis->analyze($meeting, $segments);
        $recipient = $meeting->user->phone_number ?: $meeting->contact?->phone_number;
        if (blank($recipient)) {
            throw new \RuntimeException('Não há destinatário WhatsApp vinculado à reunião.');
        }
        $meeting->update(['summary' => $summary, 'status' => 'analyzing']);
        $delivery->send((string) $recipient, $summary, $meeting->user_id);
        $meeting->update(['status' => 'reported', 'reported_at' => now()]);
    }

    public function failed(Throwable $exception): void
    {
        $changed = Meeting::whereKey($this->meetingId)->whereNotIn('status', ['reported', 'needs_review'])->update([
            'status' => 'needs_review', 'failure_code' => 'transcript_analysis_failed',
        ]);
        if ($changed) SendMeetingReviewNotice::dispatch($this->meetingId);
        Log::warning('Análise de transcrição Vexa falhou', ['meeting_id' => $this->meetingId, 'exception' => $exception::class]);
    }
}
