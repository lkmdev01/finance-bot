<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeMeetingTranscript;
use App\Jobs\SendMeetingReviewNotice;
use App\Models\MeetingTranscriptionTask;
use App\Services\Meetings\MeetingTranscriptionQuality;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MeetingTranscriptionWorkerController extends Controller
{
    public function claim(): JsonResponse|Response
    {
        $task = DB::transaction(function () {
            $now = now();
            MeetingTranscriptionTask::query()->where('status', 'processing')
                ->where('lease_expires_at', '<=', $now)->whereColumn('attempts', '>=', 'max_attempts')
                ->with('meeting')->get()->each(function (MeetingTranscriptionTask $expired) {
                    $expired->update(['status' => 'needs_review', 'lease_token_hash' => null, 'last_error_code' => 'retry_limit_exceeded']);
                    $expired->meeting?->update(['status' => 'needs_review', 'failure_code' => 'transcription_retry_limit']);
                    if ($expired->meeting) {
                        SendMeetingReviewNotice::dispatch($expired->meeting_id)->afterCommit();
                    }
                });

            $task = MeetingTranscriptionTask::query()
                ->where(function ($query) use ($now) {
                    $query->where(fn ($pending) => $pending->where('status', 'pending')->where('available_at', '<=', $now))
                        ->orWhere(fn ($expired) => $expired->where('status', 'processing')->where('lease_expires_at', '<=', $now));
                })
                ->whereColumn('attempts', '<', 'max_attempts')
                ->orderBy('available_at')->lockForUpdate()->first();

            if (! $task) {
                return null;
            }
            $leaseToken = Str::random(64);
            $task->update([
                'status' => 'processing',
                'attempts' => $task->attempts + 1,
                'lease_token_hash' => hash('sha256', $leaseToken),
                'lease_expires_at' => $now->copy()->addSeconds(min(3600, max(120, (int) config('vexa.transcription_lease_seconds', 600)))),
                'heartbeat_at' => $now,
                'last_error_code' => null,
            ]);
            $meeting = $task->meeting;
            $meeting->update(['status' => 'transcribing', 'failure_code' => null]);

            return [
                'task' => $task->fresh(),
                'meeting' => $meeting->fresh(),
                'lease_token' => $leaseToken,
            ];
        });

        if (! $task) {
            return response()->noContent();
        }

        return response()->json([
            'status' => 'claimed',
            'task_id' => $task['task']->id,
            'lease_token' => $task['lease_token'],
            'lease_expires_at' => $task['task']->lease_expires_at?->toIso8601String(),
            'meeting' => [
                'vexa_meeting_id' => $task['meeting']->vexa_meeting_id,
            ],
        ]);
    }

    public function heartbeat(Request $request, MeetingTranscriptionTask $task): JsonResponse
    {
        $token = (string) $request->header('X-Meeting-Lease', '');
        $leaseSeconds = min(3600, max(120, (int) config('vexa.transcription_lease_seconds', 600)));
        $renewed = DB::transaction(function () use ($task, $token, $leaseSeconds) {
            $lease = MeetingTranscriptionTask::query()->whereKey($task->id)->where('status', 'processing')
                ->where('lease_token_hash', hash('sha256', $token))->where('lease_expires_at', '>', now())
                ->lockForUpdate()->first();
            if (! $lease) {
                return false;
            }

            $now = now();
            $leaseExpiresAt = $now->copy()->addSeconds($leaseSeconds);
            if ($lease->lease_expires_at && ! $leaseExpiresAt->isAfter($lease->lease_expires_at)) {
                // MySQL timestamps have second precision here. Advance at least
                // one second so a same-second heartbeat still renews the lease.
                $leaseExpiresAt = $lease->lease_expires_at->copy()->addSecond();
            }
            $lease->update([
                'heartbeat_at' => $now,
                'lease_expires_at' => $leaseExpiresAt,
            ]);

            return true;
        });

        return response()->json(['status' => $renewed ? 'renewed' : 'lease_lost'], $renewed ? 200 : 409);
    }

    public function result(Request $request, MeetingTranscriptionTask $task, MeetingTranscriptionQuality $quality): JsonResponse
    {
        $data = $request->validate([
            'language' => ['required', 'in:pt'],
            'model' => ['required', 'string', 'max:120'],
            'recording_id' => ['required', 'string', 'max:128'],
            'media_file_id' => ['required', 'string', 'max:128'],
            'audio_duration_seconds' => ['required', 'numeric', 'min:0', 'max:21600'],
            'speech_duration_seconds' => ['required', 'numeric', 'min:0', 'max:21600'],
            'segments' => ['present', 'array', 'max:10000'],
            'segments.*.start' => ['required', 'numeric', 'min:0'],
            'segments.*.end' => ['required', 'numeric', 'gt:segments.*.start'],
            'segments.*.text' => ['required', 'string', 'max:5000'],
            'segments.*.speaker' => ['nullable', 'string', 'max:120'],
        ]);
        if (strlen(json_encode($data['segments'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '')
            > max(1024, (int) config('vexa.transcription_max_payload_bytes', 4 * 1024 * 1024))) {
            throw ValidationException::withMessages(['segments' => 'A transcrição excede o limite permitido.']);
        }
        $token = (string) $request->header('X-Meeting-Lease', '');

        $response = DB::transaction(function () use ($task, $data, $token, $quality) {
            $locked = MeetingTranscriptionTask::query()->with('meeting')->lockForUpdate()->find($task->id);
            if (! $locked) {
                return ['status' => 'not_found', 'code' => 404];
            }
            if ($locked->status === 'received') {
                // Re-enqueueing is safe: the analysis job is idempotent and
                // this recovers a queue outage after the transcript commit.
                return ['status' => 'duplicate', 'code' => 200, 'meeting_id' => $locked->meeting_id, 'retry_analysis' => true];
            }
            if ($locked->status === 'needs_review') {
                return ['status' => 'duplicate', 'code' => 200];
            }
            if ($locked->status !== 'processing' || ! $locked->lease_expires_at?->isFuture()
                || ! hash_equals((string) $locked->lease_token_hash, hash('sha256', $token))) {
                return ['status' => 'lease_lost', 'code' => 409];
            }

            $segments = array_values(array_filter($data['segments'], fn ($segment) => trim($segment['text']) !== ''));
            foreach ($segments as $segment) {
                if ($segment['end'] > $data['audio_duration_seconds'] + 2) {
                    throw ValidationException::withMessages(['segments' => 'Os tempos precisam corresponder ao áudio original.']);
                }
            }
            $assessment = $quality->assess($segments, (float) $data['speech_duration_seconds']);
            $meeting = $locked->meeting;
            $retentionExpiry = $meeting->expires_at
                ?? now()->addDays(min(3650, max(1, (int) config('vexa.retention_days', 90))));
            $transcript = implode("\n", array_map(function (array $segment) {
                $speaker = trim((string) ($segment['speaker'] ?? ''));

                return '['.gmdate('H:i:s', (int) floor($segment['start'])).'] '
                    .($speaker !== '' ? $speaker.': ' : '').trim($segment['text']);
            }, $segments));
            $meeting->update([
                'transcript' => $transcript,
                'transcript_segments' => $segments,
                'transcript_expires_at' => $retentionExpiry,
                'expires_at' => $retentionExpiry,
                'status' => $assessment['accepted'] ? 'analyzing' : 'needs_review',
                'failure_code' => $assessment['accepted'] ? null : 'transcript_insufficient',
            ]);
            $locked->update([
                'status' => $assessment['accepted'] ? 'received' : 'needs_review',
                'recording_id' => $data['recording_id'],
                'media_file_id' => $data['media_file_id'],
                'processing_metadata' => [
                    'language' => $data['language'], 'model' => $data['model'],
                    'audio_duration_seconds' => $data['audio_duration_seconds'],
                    'speech_duration_seconds' => $assessment['speech_seconds'],
                    'word_count' => $assessment['word_count'],
                    'segment_count' => count($segments),
                ],
                'lease_token_hash' => null,
                'lease_expires_at' => null,
                'received_at' => now(),
                'last_error_code' => $assessment['reason'],
            ]);

            return [
                'status' => $assessment['accepted'] ? 'accepted' : 'needs_review',
                'code' => 200,
                'meeting_id' => $meeting->id,
            ];
        });

        if (isset($response['meeting_id'])) {
            if ($response['status'] === 'accepted' || ($response['retry_analysis'] ?? false)) {
                AnalyzeMeetingTranscript::dispatch($response['meeting_id']);
            } elseif ($response['status'] === 'needs_review') {
                SendMeetingReviewNotice::dispatch($response['meeting_id']);
            }
        }

        return response()->json(['status' => $response['status']], $response['code']);
    }

    public function fail(Request $request, MeetingTranscriptionTask $task): JsonResponse
    {
        $data = $request->validate(['error_code' => ['required', 'regex:/^[a-z0-9_]{1,64}$/']]);
        $token = (string) $request->header('X-Meeting-Lease', '');
        $status = DB::transaction(function () use ($task, $data, $token) {
            $locked = MeetingTranscriptionTask::query()->with('meeting')->lockForUpdate()->find($task->id);
            if (! $locked || $locked->status !== 'processing' || ! $locked->lease_expires_at?->isFuture()
                || ! hash_equals((string) $locked->lease_token_hash, hash('sha256', $token))) {
                return null;
            }
            $backoff = config('vexa.transcription_retry_backoff_seconds', [60, 300, 900, 1800]);
            $retryDelay = (int) ($backoff[min(max(0, $locked->attempts - 1), count($backoff) - 1)] ?? 1800);
            $retry = $locked->attempts < $locked->max_attempts;
            $locked->update([
                'status' => $retry ? 'pending' : 'needs_review',
                'available_at' => now()->addSeconds($retry ? $retryDelay : 0),
                'lease_token_hash' => null,
                'lease_expires_at' => null,
                'last_error_code' => $data['error_code'],
            ]);
            $locked->meeting?->update([
                'status' => $retry ? 'awaiting_transcription' : 'needs_review',
                'failure_code' => $retry ? null : 'transcription_retry_limit',
            ]);

            return [
                'status' => $retry ? 'retry_scheduled' : 'needs_review',
                'meeting_id' => ! $retry ? $locked->meeting_id : null,
            ];
        });
        if (($status['meeting_id'] ?? null) !== null) {
            SendMeetingReviewNotice::dispatch($status['meeting_id']);
        }

        return $status === null
            ? response()->json(['status' => 'lease_lost'], 409)
            : response()->json(['status' => $status['status']]);
    }
}
