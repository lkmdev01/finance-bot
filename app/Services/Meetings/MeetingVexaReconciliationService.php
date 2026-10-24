<?php

namespace App\Services\Meetings;

use App\Jobs\SendMeetingDurationWarning;
use App\Jobs\StopMeetingBot;
use App\Models\Meeting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class MeetingVexaReconciliationService
{
    private const RECONCILABLE_STATUSES = [
        'requesting', 'requested', 'joining', 'connecting', 'awaiting_admission', 'needs_help', 'active', 'stopping', 'completed', 'failed',
    ];

    private const REMOTE_STATUSES = [
        'requested', 'joining', 'connecting', 'awaiting_admission', 'needs_help', 'active', 'stopping', 'completed', 'failed',
    ];

    public function __construct(
        private readonly VexaApiClient $vexa,
        private readonly MeetingTranscriptionTaskService $tasks,
    ) {}

    public function reconcile(Meeting $meeting): void
    {
        if (! in_array($meeting->status, self::RECONCILABLE_STATUSES, true) || blank($meeting->vexa_meeting_id)) {
            return;
        }

        try {
            $payload = $this->vexa->meeting((string) $meeting->vexa_meeting_id);
        } catch (Throwable $exception) {
            Log::warning('Falha ao reconciliar estado da reunião Vexa', [
                'meeting_id' => $meeting->id,
                'exception' => $exception::class,
            ]);

            return;
        }

        $remoteStatus = data_get($payload, 'status')
            ?? data_get($payload, 'meeting.status')
            ?? data_get($payload, 'data.status');

        if (! is_string($remoteStatus) || ! in_array($remoteStatus, self::REMOTE_STATUSES, true)) {
            Log::notice('Resposta Vexa sem estado reconhecido; reunião preservada para nova reconciliação', [
                'meeting_id' => $meeting->id,
            ]);

            return;
        }

        if ($remoteStatus === 'completed') {
            $this->markCompleted($meeting);

            return;
        }

        if ($remoteStatus === 'failed') {
            $this->markFailed($meeting);

            return;
        }

        if ($remoteStatus === 'active') {
            $this->observeActive($meeting, $payload);
        } elseif ($meeting->status === 'stopping') {
            $current = $meeting->fresh();
            $retryAfter = max(60, (int) config('vexa.stop_retry_seconds', 180));
            if ($current->stop_requested_at === null || $current->stop_requested_at->lte(now()->subSeconds($retryAfter))) {
                $this->requestControlledStop($current, $current->stop_reason ?: 'reconciliation');
            }
        } else {
            $meeting->forceFill(['status' => $remoteStatus])->save();
        }
    }

    private function observeActive(Meeting $meeting, array $payload): void
    {
        $meeting->refresh();
        if (! in_array($meeting->status, ['stopping', 'completed', 'awaiting_transcription', 'transcribing', 'analyzing', 'reported', 'failed', 'needs_review', 'expired'], true)) {
            $attributes = [];
            if ($meeting->status !== 'active') {
                $attributes['status'] = 'active';
            }
            if (! $meeting->active_since) {
                $attributes['active_since'] = $this->remoteStartTime($payload) ?? now();
            }
            if ($attributes !== []) {
                $meeting->forceFill($attributes)->save();
            }
        }

        $meeting->refresh();
        if (! $meeting->active_since) {
            return;
        }

        $activeSince = CarbonImmutable::instance($meeting->active_since);
        $maxMinutes = (int) config('vexa.max_meeting_duration_minutes', 240);
        $warningMinutes = min((int) config('vexa.max_meeting_duration_warning_minutes', 15), $maxMinutes - 1);
        $stopAt = $activeSince->addMinutes($maxMinutes);
        $warnAt = $stopAt->subMinutes($warningMinutes);

        if (now()->greaterThanOrEqualTo($warnAt) && ! $meeting->max_duration_warning_sent_at) {
            $this->scheduleDurationWarning($meeting, $stopAt);
        }

        if (now()->greaterThanOrEqualTo($stopAt)) {
            $this->requestControlledStop($meeting, 'max_duration');
        }

        if ($meeting->fresh()->status === 'stopping') {
            $current = $meeting->fresh();
            $retryAfter = max(60, (int) config('vexa.stop_retry_seconds', 180));
            if ($current->stop_requested_at === null || $current->stop_requested_at->lte(now()->subSeconds($retryAfter))) {
                $this->requestControlledStop($current, $current->stop_reason ?: 'reconciliation');
            }
        }
    }

    private function remoteStartTime(array $payload): ?CarbonImmutable
    {
        $value = data_get($payload, 'start_time')
            ?? data_get($payload, 'started_at')
            ?? data_get($payload, 'meeting.start_time')
            ?? data_get($payload, 'data.start_time');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function scheduleDurationWarning(Meeting $meeting, CarbonImmutable $stopAt): void
    {
        $shouldSend = DB::transaction(function () use ($meeting): bool {
            $locked = Meeting::query()->lockForUpdate()->find($meeting->id);
            if (! $locked || $locked->status !== 'active' || $locked->max_duration_warning_sent_at) {
                return false;
            }

            $locked->forceFill(['max_duration_warning_sent_at' => now()])->save();

            return true;
        });

        if ($shouldSend) {
            SendMeetingDurationWarning::dispatch($meeting->id, $stopAt->toIso8601String());
            Log::notice('Aviso de duração máxima da reunião enfileirado', ['meeting_id' => $meeting->id]);
        }
    }

    public function requestControlledStop(Meeting $meeting, string $reason): bool
    {
        if (! in_array($reason, ['user', 'max_duration', 'reconciliation'], true)) {
            return false;
        }

        $shouldDispatch = DB::transaction(function () use ($meeting, $reason): bool {
            $locked = Meeting::query()->lockForUpdate()->find($meeting->id);
            if (! $locked || ! in_array($locked->status, ['active', 'stopping'], true)) {
                return false;
            }

            $retryAfter = max(60, (int) config('vexa.stop_retry_seconds', 180));
            if ($locked->status === 'stopping'
                && $locked->stop_requested_at
                && $locked->stop_requested_at->gt(now()->subSeconds($retryAfter))) {
                return false;
            }

            $locked->forceFill([
                'status' => 'stopping',
                'stop_requested_at' => now(),
                'stop_reason' => $reason,
            ])->save();

            return true;
        });

        if ($shouldDispatch) {
            StopMeetingBot::dispatch($meeting->id);
            Log::notice('Encerramento controlado do bot enfileirado', [
                'meeting_id' => $meeting->id,
                'reason' => $reason,
            ]);
        }

        return $shouldDispatch;
    }

    private function markCompleted(Meeting $meeting): void
    {
        $completed = DB::transaction(function () use ($meeting): ?Meeting {
            $locked = Meeting::query()->lockForUpdate()->find($meeting->id);
            if (! $locked || in_array($locked->status, ['transcribing', 'analyzing', 'reported', 'needs_review', 'expired'], true)) {
                return null;
            }

            if ($locked->status === 'awaiting_transcription') {
                // Repair a missing task without moving an already-reconciled meeting backwards.
                $this->tasks->createFor($locked);

                return null;
            }

            $retentionDays = min(3650, max(1, (int) config('vexa.retention_days', 90)));
            $locked->forceFill([
                'status' => 'awaiting_transcription',
                'completed_at' => $locked->completed_at ?? now(),
                'expires_at' => $locked->expires_at ?? now()->addDays($retentionDays),
            ])->save();

            $this->tasks->createFor($locked);

            return $locked;
        });

        if ($completed) {
            Log::info('Reunião concluída no Vexa e transcrição posterior garantida', ['meeting_id' => $meeting->id]);
        }
    }

    private function markFailed(Meeting $meeting): void
    {
        DB::transaction(function () use ($meeting): void {
            $locked = Meeting::query()->lockForUpdate()->find($meeting->id);
            if (! $locked
                || in_array($locked->status, ['reported', 'awaiting_transcription', 'transcribing', 'analyzing', 'needs_review', 'expired'], true)
                || ($locked->status === 'failed' && $locked->failure_code === 'vexa_meeting_failed')) {
                return;
            }

            $retentionDays = min(3650, max(1, (int) config('vexa.retention_days', 90)));
            $locked->forceFill([
                'status' => 'failed',
                'failure_code' => 'vexa_meeting_failed',
                'completed_at' => $locked->completed_at ?? now(),
                'expires_at' => $locked->expires_at ?? now()->addDays($retentionDays),
            ])->save();
        });
    }
}
