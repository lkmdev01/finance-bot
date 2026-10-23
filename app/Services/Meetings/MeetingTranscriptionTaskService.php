<?php

namespace App\Services\Meetings;

use App\Models\Meeting;
use App\Models\MeetingTranscriptionTask;
use Illuminate\Database\QueryException;

class MeetingTranscriptionTaskService
{
    public function createFor(Meeting $meeting): MeetingTranscriptionTask
    {
        $maxAttempts = min(20, max(1, (int) config('vexa.transcription_max_attempts', 5)));
        try {
            return MeetingTranscriptionTask::query()->firstOrCreate(
                ['meeting_id' => $meeting->id],
                [
                    'status' => 'pending',
                    'attempts' => 0,
                    'max_attempts' => $maxAttempts,
                    'available_at' => now(),
                ],
            );
        } catch (QueryException $exception) {
            $existing = MeetingTranscriptionTask::query()->where('meeting_id', $meeting->id)->first();
            if ($existing) {
                return $existing;
            }
            throw $exception;
        }
    }
}
