<?php

namespace App\Jobs;

use App\Models\Meeting;
use App\Services\WhatsApp\WhatsAppReplyDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendMeetingReviewNotice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $meetingId) {}

    public function backoff(): array
    {
        return [60, 180];
    }

    public function handle(WhatsAppReplyDelivery $delivery): void
    {
        $meeting = Meeting::find($this->meetingId);
        if (! $meeting || $meeting->status !== 'needs_review') {
            return;
        }
        $recipient = $meeting->user->phone_number ?: $meeting->contact?->phone_number;
        if (blank($recipient)) {
            return;
        }
        $delivery->send((string) $recipient,
            'A transcrição da reunião ficou vazia ou não trouxe conteúdo suficiente. Marquei a reunião para revisão e não gerei decisões, tarefas ou um resumo definitivo.',
            $meeting->user_id);
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('Aviso de revisão da reunião não pôde ser enviado', [
            'meeting_id' => $this->meetingId,
            'exception' => $exception::class,
        ]);
    }
}
