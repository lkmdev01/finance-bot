<?php

namespace App\Jobs;

use App\Models\Meeting;
use App\Services\WhatsApp\WhatsAppReplyDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendMeetingDurationWarning implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 45;

    public function __construct(public readonly int $meetingId, public readonly string $stopAt) {}

    public function backoff(): array
    {
        return [30, 90];
    }

    public function handle(WhatsAppReplyDelivery $delivery): void
    {
        $meeting = Meeting::find($this->meetingId);
        if (! $meeting || ($meeting->status !== 'active' && ! ($meeting->status === 'stopping' && $meeting->stop_reason === 'max_duration'))) {
            return;
        }

        $recipient = $meeting->user->phone_number ?: $meeting->contact?->phone_number;
        if (blank($recipient)) {
            return;
        }

        $stopTime = CarbonImmutable::parse($this->stopAt)->timezone(config('app.timezone'))->format('H:i');
        $delivery->send(
            (string) $recipient,
            "Aviso: esta reunião está próxima do limite máximo de duração. O bot será encerrado automaticamente por volta de {$stopTime}; a gravação será finalizada antes da transcrição.",
            $meeting->user_id,
        );
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('Aviso de limite de duração não pôde ser enviado', [
            'meeting_id' => $this->meetingId,
            'exception' => $exception::class,
        ]);
    }
}
