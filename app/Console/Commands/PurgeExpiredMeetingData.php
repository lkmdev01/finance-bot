<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Models\MeetingWebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeExpiredMeetingData extends Command
{
    protected $signature = 'meetings:purge-expired-data';

    protected $description = 'Remove transcrições e metadados locais expirados conforme a política Vexa';

    public function handle(): int
    {
        $expiredCount = 0;
        Meeting::query()->whereNotNull('expires_at')->where('expires_at', '<=', now())->where('status', '!=', 'expired')
            ->orderBy('id')->chunkById(100, function ($meetings) use (&$expiredCount) {
                foreach ($meetings as $meeting) {
                    DB::transaction(function () use ($meeting, &$expiredCount) {
                        $meeting = Meeting::query()->lockForUpdate()->find($meeting->id);
                        if (! $meeting || $meeting->expires_at?->isFuture()) {
                            return;
                        }

                        $meeting->forceFill([
                            'native_meeting_id' => null,
                            'meeting_url' => null,
                            'vexa_meeting_id' => null,
                            'vexa_meeting_id_hash' => null,
                            'transcript' => null,
                            'transcript_segments' => null,
                            'summary' => null,
                            'status' => 'expired',
                            'failure_code' => 'retention_expired',
                        ])->save();
                        $meeting->transcriptionTask?->update([
                            'status' => 'expired',
                            'lease_token_hash' => null,
                            'lease_expires_at' => null,
                            'recording_id' => null,
                            'media_file_id' => null,
                            'processing_metadata' => null,
                        ]);
                        $expiredCount++;
                    });
                }
            });

        $webhookCutoff = now()->subDays(min(3650, max(1, (int) config('vexa.retention_days', 90))));
        $webhookCount = MeetingWebhookEvent::query()->where('received_at', '<=', $webhookCutoff)
            ->where(function ($query) {
                $query->whereNull('meeting_id')
                    ->orWhereHas('meeting', fn ($meeting) => $meeting->where('status', 'expired'));
            })->delete();
        $this->info("Dados locais expirados removidos: {$expiredCount} reuniões; {$webhookCount} eventos de webhook.");
        $this->comment('Gravações originais no Vexa não são excluídas por este comando.');

        return self::SUCCESS;
    }
}
