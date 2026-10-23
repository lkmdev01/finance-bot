<?php

namespace App\Services\Meetings;

class MeetingTranscriptionQuality
{
    /** @return array{accepted:bool,word_count:int,speech_seconds:float,reason:?string} */
    public function assess(array $segments, float $reportedSpeechSeconds): array
    {
        $text = collect($segments)
            ->pluck('text')
            ->filter(fn ($value) => is_string($value))
            ->map(fn ($value) => trim($value))
            ->filter()
            ->implode(' ');
        $wordCount = preg_match_all('/[\p{L}\p{N}]+(?:[\'’-][\p{L}\p{N}]+)*/u', $text) ?: 0;
        $intervals = collect($segments)->map(fn ($segment) => [
            max(0, (float) ($segment['start'] ?? 0)),
            max(0, (float) ($segment['end'] ?? 0)),
        ])->filter(fn ($interval) => $interval[1] > $interval[0])->sortBy(0)->values();
        $unionSeconds = 0.0;
        $start = null;
        $end = null;
        foreach ($intervals as [$nextStart, $nextEnd]) {
            if ($start === null) {
                [$start, $end] = [$nextStart, $nextEnd];
            } elseif ($nextStart <= $end) {
                $end = max($end, $nextEnd);
            } else {
                $unionSeconds += $end - $start;
                [$start, $end] = [$nextStart, $nextEnd];
            }
        }
        if ($start !== null) {
            $unionSeconds += $end - $start;
        }
        $speechSeconds = max($reportedSpeechSeconds, $unionSeconds);
        $accepted = $wordCount >= max(1, (int) config('vexa.transcription_min_words', 40))
            && $speechSeconds >= max(1, (int) config('vexa.transcription_min_speech_seconds', 30));

        return [
            'accepted' => $accepted,
            'word_count' => $wordCount,
            'speech_seconds' => $speechSeconds,
            'reason' => $accepted ? null : 'transcript_insufficient',
        ];
    }
}
