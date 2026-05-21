<?php

namespace App\Services\WhatsApp;

use App\Services\WhatsApp\Support\NormalizesWhatsAppText;
use Carbon\Carbon;
use Illuminate\Support\Str;

class ReminderMessageParser
{
    use NormalizesWhatsAppText;

    public function looksLikeCreateIntent(string $normalizedMessage): bool
    {
        return $this->containsAnyText($normalizedMessage, [
            'me lembra',
            'me lembre',
            'lembra de',
            'lembrar de',
            'lembrete',
            'todo mes',
            'todo dia',
            'cada mes',
            'cada ano',
        ]);
    }

    public function parse(string $originalMessage): ?array
    {
        $partial = $this->parsePartialCreate($originalMessage);

        if ($partial === null || empty($partial['title']) || empty($partial['message']) || empty($partial['frequency'])) {
            return null;
        }

        if ($partial['frequency'] === 'monthly' && empty($partial['day_of_month'])) {
            return null;
        }

        if ($partial['frequency'] === 'yearly' && (empty($partial['day_of_month']) || empty($partial['month_of_year']))) {
            return null;
        }

        if ($partial['frequency'] === 'once' && empty($partial['next_trigger_at'])) {
            return null;
        }

        return $partial;
    }

    public function parsePartialCreate(string $originalMessage): ?array
    {
        $clean = $this->cleanText($originalMessage);
        $normalized = $this->normalizeText($clean);

        if (! $this->looksLikeCreateIntent($normalized)) {
            return null;
        }

        $data = [
            'title' => $this->extractReminderTitle($clean),
            'message' => null,
            'frequency' => null,
            'day_of_month' => null,
            'month_of_year' => null,
            'next_trigger_at' => null,
        ];

        $data = array_merge($data, $this->extractSchedule($normalized) ?? []);

        if ($data['title'] === null) {
            return null;
        }

        $data['message'] = $this->buildReminderMessage($data['title'], $data['frequency']);

        return $data;
    }

    public function parseScheduleFollowUp(string $message, array $pendingReminder = []): ?array
    {
        $schedule = $this->extractSchedule($this->normalizeText($this->cleanText($message)));
        if ($schedule === null) {
            return null;
        }

        $title = $pendingReminder['title'] ?? null;
        if ($title === null || $title === '') {
            return null;
        }

        $parsed = array_merge($pendingReminder, $schedule);
        $parsed['title'] = $title;
        $parsed['message'] = $this->buildReminderMessage($title, $parsed['frequency'] ?? null);

        return $parsed;
    }

    private function extractSchedule(string $normalized): ?array
    {
        if (preg_match('/(?:mes\s+que\s+vem|proximo\s+mes)\s+dia\s+(\d{1,2})\b/u', $normalized, $matches)
            || preg_match('/\bdia\s+(\d{1,2})\s+(?:(?:do|de|no|na)\s+)?(?:mes\s+que\s+vem|proximo\s+mes)\b/u', $normalized, $matches)) {
            $day = max(1, min(31, (int) $matches[1]));

            return [
                'frequency' => 'once',
                'day_of_month' => null,
                'month_of_year' => null,
                'next_trigger_at' => $this->oneTimeNextMonthTrigger($day)->toIso8601String(),
            ];
        }

        if (preg_match('/\b(todo|cada)\s+mes\b/u', $normalized) || str_contains($normalized, 'mensal')) {
            $data = [
                'frequency' => 'monthly',
                'day_of_month' => null,
                'month_of_year' => null,
                'next_trigger_at' => null,
            ];

            if (preg_match('/\bdia\s+(\d{1,2})\b/u', $normalized, $matches)) {
                $data['day_of_month'] = max(1, min(31, (int) $matches[1]));
                $data['next_trigger_at'] = $this->nextMonthlyTrigger($data['day_of_month'])->toIso8601String();
            }

            return $data;
        }

        if (preg_match('/\bdia\s+(\d{1,2})\s+(?:desse|deste)\s+mes\b/u', $normalized, $matches)) {
            $day = max(1, min(31, (int) $matches[1]));

            return [
                'frequency' => 'once',
                'day_of_month' => null,
                'month_of_year' => null,
                'next_trigger_at' => $this->oneTimeThisMonthTrigger($day)->toIso8601String(),
            ];
        }

        if (preg_match('/\bdia\s+(\d{1,2})\/(\d{1,2})(?:\/(\d{4}))?\b/u', $normalized, $matches)) {
            $day = max(1, min(31, (int) $matches[1]));
            $month = max(1, min(12, (int) $matches[2]));
            $year = isset($matches[3]) && $matches[3] !== '' ? (int) $matches[3] : null;

            if ($year !== null) {
                return [
                    'frequency' => 'once',
                    'day_of_month' => null,
                    'month_of_year' => null,
                    'next_trigger_at' => Carbon::create($year, $month, min($day, Carbon::create($year, $month, 1)->daysInMonth), 9, 0, 0, config('app.timezone'))->toIso8601String(),
                ];
            }

            return [
                'frequency' => 'yearly',
                'day_of_month' => $day,
                'month_of_year' => $month,
                'next_trigger_at' => $this->nextYearlyTrigger($day, $month)->toIso8601String(),
            ];
        }

        if (preg_match('/\bdia\s+(\d{1,2})\s+de\s+([[:alpha:]]+)\b/u', $normalized, $matches)) {
            $month = $this->resolveMonthNumber($matches[2] ?? '');
            if ($month !== null) {
                $day = max(1, min(31, (int) $matches[1]));

                return [
                    'frequency' => 'yearly',
                    'day_of_month' => $day,
                    'month_of_year' => $month,
                    'next_trigger_at' => $this->nextYearlyTrigger($day, $month)->toIso8601String(),
                ];
            }
        }

        return null;
    }

    private function extractReminderTitle(string $cleanMessage): ?string
    {
        $title = $cleanMessage;
        $title = preg_replace('/^\s*(?:todo\s+m\S*s|todo\s+mes|cada\s+m\S*s|todo\s+dia|cada\s+ano)\s*/iu', '', $title) ?? $title;
        $title = preg_replace('/\b(?:mes\s+que\s+vem|proximo\s+mes)\s+dia\s+\d{1,2}\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\bdia\s+\d{1,2}\s+(?:(?:do|de|no|na)\s+)?(?:mes\s+que\s+vem|proximo\s+mes)\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\bdia\s+\d{1,2}(?:\/\d{1,2}(?:\/\d{4})?)?\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\bdia\s+\d{1,2}\s+(?:desse|deste)\s+m\S*s\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\bdia\s+\d{1,2}\s+de\s+[[:alpha:]]+\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\b(?:desse|deste)\s+m\S*s\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\bmes\s+que\s+vem\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\bproximo\s+mes\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\b(todo|cada)\s+m\S*s\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\bmensal\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\b(?:me\s+lembra(?:r)?(?:\s+de)?|me\s+lembre(?:\s+de)?|lembrete(?:\s+para)?|lembrar\s+de|lembra\s+de)\b/iu', '', $title) ?? $title;
        $title = preg_replace('/^\s*(?:(?:no|na|do|da|de)\s+)+/iu', '', $title) ?? $title;
        $title = preg_replace('/\s+/u', ' ', $title) ?? $title;
        $title = trim($title, " \t\n\r\0\x0B,.;:-?");

        if ($title === '') {
            return null;
        }

        return Str::title($title);
    }

    private function buildReminderMessage(string $title, ?string $frequency): string
    {
        $base = trim($title);

        return match ($frequency) {
            'yearly' => "Lembrete: hoje e dia de {$base}.",
            'monthly' => "Lembrete do mes: {$base}.",
            default => "Lembrete: {$base}.",
        };
    }

    private function oneTimeThisMonthTrigger(int $day): Carbon
    {
        $date = now(config('app.timezone'))->copy()->startOfMonth()->day(min($day, now(config('app.timezone'))->daysInMonth))->setTime(9, 0, 0);

        if ($date->isPast()) {
            $date->addMonthNoOverflow()->day(min($day, $date->daysInMonth));
        }

        return $date;
    }

    private function oneTimeNextMonthTrigger(int $day): Carbon
    {
        $date = now(config('app.timezone'))->copy()->addMonthNoOverflow()->startOfMonth();
        $date->day(min($day, $date->daysInMonth))->setTime(9, 0, 0);

        return $date;
    }

    private function nextMonthlyTrigger(int $day): Carbon
    {
        $reference = now(config('app.timezone'))->copy()->setTime(9, 0, 0);
        $candidate = $reference->copy()->startOfMonth()->day(min($day, $reference->daysInMonth));

        if ($candidate->lt($reference)) {
            $candidate = $reference->copy()->addMonthNoOverflow()->startOfMonth()->day(min($day, $reference->copy()->addMonthNoOverflow()->daysInMonth));
        }

        return $candidate->setTime(9, 0, 0);
    }

    private function nextYearlyTrigger(int $day, int $month): Carbon
    {
        $reference = now(config('app.timezone'))->copy()->setTime(9, 0, 0);
        $year = $reference->year;
        $candidate = Carbon::create($year, $month, 1, 9, 0, 0, config('app.timezone'));
        $candidate->day(min($day, $candidate->daysInMonth));

        if ($candidate->lt($reference)) {
            $candidate = Carbon::create($year + 1, $month, 1, 9, 0, 0, config('app.timezone'));
            $candidate->day(min($day, $candidate->daysInMonth));
        }

        return $candidate;
    }

    private function resolveMonthNumber(string $monthName): ?int
    {
        $monthName = $this->normalizeText($monthName);

        return [
            'janeiro' => 1,
            'fevereiro' => 2,
            'marco' => 3,
            'abril' => 4,
            'maio' => 5,
            'junho' => 6,
            'julho' => 7,
            'agosto' => 8,
            'setembro' => 9,
            'outubro' => 10,
            'novembro' => 11,
            'dezembro' => 12,
        ][$monthName] ?? null;
    }

    private function cleanText(string $message): string
    {
        return app(IncomingMessageNormalizer::class)->clean($message);
    }
}
