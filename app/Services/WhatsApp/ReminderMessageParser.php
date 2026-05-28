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
            'amanha',
            'hoje',
            'todo mes',
            'todos os dias',
            'todo dia',
            'cada mes',
            'cada ano',
            'todo ano',
            'anual',
            'anualmente',
            'semanal',
            'toda semana',
            'cada semana',
            'diario',
            'diariamente',
        ]) || preg_match('/\d{1,2}\/\d{1,2}(?:\/\d{2,4})?/', $normalizedMessage) === 1;
    }

    public function parse(string $originalMessage): ?array
    {
        $partial = $this->parsePartialCreate($originalMessage);

        if ($partial === null || empty($partial['title']) || empty($partial['message']) || empty($partial['frequency'])) {
            return null;
        }

        if ($partial['frequency'] === 'weekly' && ! isset($partial['day_of_week'])) {
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

        $time = $this->extractTriggerTime($clean, $normalized) ?? '09:00:00';

        $title = $this->extractReminderTitle($clean);

        $data = [
            'title' => $title,
            'message' => null,
            'frequency' => null,
            'day_of_week' => null,
            'day_of_month' => null,
            'month_of_year' => null,
            'trigger_time' => $time,
            'next_trigger_at' => null,
        ];

        $data = array_merge($data, $this->extractSchedule($normalized, $time) ?? []);

        if ($data['title'] === null) {
            return null;
        }

        $data['message'] = $this->buildReminderMessage($clean, $data['title'], $data['frequency']);

        return $data;
    }

    public function extractTitle(string $message): ?string
    {
        return $this->extractReminderTitle($this->cleanText($message));
    }

    public function buildMessage(string $title, ?string $frequency): string
    {
        return $this->buildReminderMessage($this->cleanText($title), $title, $frequency);
    }

    public function parseScheduleFollowUp(string $message, array $pendingReminder = []): ?array
    {
        $clean = $this->cleanText($message);
        $normalized = $this->normalizeText($clean);
        $time = $this->extractTriggerTime($clean, $normalized) ?? ($pendingReminder['trigger_time'] ?? '09:00:00');

        $schedule = $this->extractSchedule($normalized, $time);
        if ($schedule === null) {
            return null;
        }

        $title = $pendingReminder['title'] ?? null;
        if ($title === null || $title === '') {
            return null;
        }

        $parsed = array_merge($pendingReminder, $schedule);
        $parsed['title'] = $title;
        $parsed['trigger_time'] = $time;
        $parsed['message'] = $this->buildReminderMessage($clean, $title, $parsed['frequency'] ?? null);

        return $parsed;
    }

    private function extractSchedule(string $normalized, string $time): ?array
    {
        // Suporte a "hoje"
        if ($this->containsAnyText($normalized, ['hoje'])) {
            return [
                'frequency' => 'once',
                'day_of_week' => null,
                'day_of_month' => null,
                'month_of_year' => null,
                'next_trigger_at' => $this->onceTodayTrigger($time)->toIso8601String(),
            ];
        }

        // Suporte a "amanhã"
        if ($this->containsAnyText($normalized, ['amanha'])) {
            return [
                'frequency' => 'once',
                'day_of_week' => null,
                'day_of_month' => null,
                'month_of_year' => null,
                'next_trigger_at' => $this->onceTomorrowTrigger($time)->toIso8601String(),
            ];
        }

        // Suporte a "em X dias" / "daqui a X dias"
        if (preg_match('/\b(?:em|daqui\s+a)\s+(\d{1,3})\s+dias?\b/u', $normalized, $matches)) {
            $days = max(1, min(365, (int) $matches[1]));

            return [
                'frequency' => 'once',
                'day_of_week' => null,
                'day_of_month' => null,
                'month_of_year' => null,
                'next_trigger_at' => $this->onceInDaysTrigger($days, $time)->toIso8601String(),
            ];
        }

        if (preg_match('/(?:mes\s+que\s+vem|proximo\s+mes)\s+dia\s+(\d{1,2})\b/u', $normalized, $matches)
            || preg_match('/\bdia\s+(\d{1,2})\s+(?:(?:do|de|no|na)\s+)?(?:mes\s+que\s+vem|proximo\s+mes)\b/u', $normalized, $matches)) {
            $day = max(1, min(31, (int) $matches[1]));

            return [
                'frequency' => 'once',
                'day_of_week' => null,
                'day_of_month' => null,
                'month_of_year' => null,
                'next_trigger_at' => $this->oneTimeNextMonthTrigger($day, $time)->toIso8601String(),
            ];
        }

        if (preg_match('/\bdia\s+(\d{1,2})\s+(?:do|de|no|na)\s+mes\s+(\d{1,2})\b/u', $normalized, $matches)
            || preg_match('/\bmes\s+(\d{1,2})\s+(?:dia\s+)?(\d{1,2})\b/u', $normalized, $matches)) {
            $day = max(1, min(31, (int) $matches[1]));
            $month = max(1, min(12, (int) $matches[2]));

            if (preg_match('/\bmes\s+(\d{1,2})\s+(?:dia\s+)?(\d{1,2})\b/u', $normalized)) {
                $month = max(1, min(12, (int) $matches[1]));
                $day = max(1, min(31, (int) $matches[2]));
            }

            if ($this->containsAnyText($normalized, ['desse ano', 'deste ano', 'este ano'])) {
                return [
                    'frequency' => 'once',
                    'day_of_week' => null,
                    'day_of_month' => null,
                    'month_of_year' => null,
                    'next_trigger_at' => $this->oneTimeThisYearTrigger($day, $month, $time)->toIso8601String(),
                ];
            }

            return [
                'frequency' => 'yearly',
                'day_of_week' => null,
                'day_of_month' => $day,
                'month_of_year' => $month,
                'next_trigger_at' => $this->nextYearlyTrigger($day, $month, $time)->toIso8601String(),
            ];
        }

        if (preg_match('/\bdia\s+(\d{1,2})\s+(?:desse|deste)\s+mes\b/u', $normalized, $matches)) {
            $day = max(1, min(31, (int) $matches[1]));

            return [
                'frequency' => 'once',
                'day_of_week' => null,
                'day_of_month' => null,
                'month_of_year' => null,
                'next_trigger_at' => $this->oneTimeThisMonthTrigger($day, $time)->toIso8601String(),
            ];
        }

        if (preg_match('/\bdia\s+(\d{1,2})\/(\d{1,2})(?:\/(\d{4}))?\b/u', $normalized, $matches)) {
            $day = max(1, min(31, (int) $matches[1]));
            $month = max(1, min(12, (int) $matches[2]));
            $year = isset($matches[3]) && $matches[3] !== '' ? (int) $matches[3] : null;

            if ($year !== null) {
                return [
                    'frequency' => 'once',
                    'day_of_week' => null,
                    'day_of_month' => null,
                    'month_of_year' => null,
                    'next_trigger_at' => $this->oneTimeSpecificDateTrigger($day, $month, $year, $time)->toIso8601String(),
                ];
            }

            return [
                'frequency' => 'yearly',
                'day_of_week' => null,
                'day_of_month' => $day,
                'month_of_year' => $month,
                'next_trigger_at' => $this->nextYearlyTrigger($day, $month, $time)->toIso8601String(),
            ];
        }

        // Support bare dates like 25/05 or 25/05/2026
        if (preg_match('/\b(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?\b/u', $normalized, $matches)) {
            $day = max(1, min(31, (int) $matches[1]));
            $month = max(1, min(12, (int) $matches[2]));
            $year = isset($matches[3]) && $matches[3] !== '' ? (int) $matches[3] : null;

            if ($year !== null && strlen((string)$year) === 4) {
                return [
                    'frequency' => 'once',
                    'day_of_week' => null,
                    'day_of_month' => null,
                    'month_of_year' => null,
                    'next_trigger_at' => $this->oneTimeSpecificDateTrigger($day, $month, $year, $time)->toIso8601String(),
                ];
            }

            // If no year provided, assume yearly recurrence
            return [
                'frequency' => 'yearly',
                'day_of_week' => null,
                'day_of_month' => $day,
                'month_of_year' => $month,
                'next_trigger_at' => $this->nextYearlyTrigger($day, $month, $time)->toIso8601String(),
            ];
        }

        if (preg_match('/\bdia\s+(\d{1,2})\s+de\s+([[:alpha:]]+)\b/u', $normalized, $matches)) {
            $month = $this->resolveMonthNumber($matches[2] ?? '');
            if ($month !== null) {
                $day = max(1, min(31, (int) $matches[1]));

                return [
                    'frequency' => 'yearly',
                    'day_of_week' => null,
                    'day_of_month' => $day,
                    'month_of_year' => $month,
                    'next_trigger_at' => $this->nextYearlyTrigger($day, $month, $time)->toIso8601String(),
                ];
            }
        }

        if (preg_match('/\bdia\s+(\d{1,2})\s+do\s+mes\s+de\s+([[:alpha:]]+)\b/u', $normalized, $matches)) {
            $month = $this->resolveMonthNumber($matches[2] ?? '');
            if ($month !== null) {
                $day = max(1, min(31, (int) $matches[1]));

                return [
                    'frequency' => 'yearly',
                    'day_of_week' => null,
                    'day_of_month' => $day,
                    'month_of_year' => $month,
                    'next_trigger_at' => $this->nextYearlyTrigger($day, $month, $time)->toIso8601String(),
                ];
            }
        }

        $weekday = $this->extractWeekday($normalized);
        if ($weekday !== null && $this->containsAnyText($normalized, ['semanal', 'semana', 'todo', 'toda', 'cada'])) {
            return [
                'frequency' => 'weekly',
                'day_of_week' => $weekday,
                'day_of_month' => null,
                'month_of_year' => null,
                'next_trigger_at' => $this->nextWeeklyTrigger($weekday, $time)->toIso8601String(),
            ];
        }

        if ($this->containsAnyText($normalized, ['diario', 'diariamente', 'todo dia', 'todos os dias', 'cada dia'])) {
            return [
                'frequency' => 'daily',
                'day_of_week' => null,
                'day_of_month' => null,
                'month_of_year' => null,
                'next_trigger_at' => $this->nextDailyTrigger($time)->toIso8601String(),
            ];
        }

        if (preg_match('/\b(todo|cada)\s+mes\b/u', $normalized) || str_contains($normalized, 'mensal')) {
            $data = [
                'frequency' => 'monthly',
                'day_of_week' => null,
                'day_of_month' => null,
                'month_of_year' => null,
                'next_trigger_at' => null,
            ];

            if (preg_match('/\bdia\s+(\d{1,2})\b/u', $normalized, $matches)) {
                $data['day_of_month'] = max(1, min(31, (int) $matches[1]));
                $data['next_trigger_at'] = $this->nextMonthlyTrigger($data['day_of_month'], $time)->toIso8601String();
            }

            return $data;
        }

        return null;
    }

    private function extractReminderTitle(string $cleanMessage): ?string
    {
        $title = $cleanMessage;
        $title = preg_replace('/^\s*(?:todo\s+m\S*s|todo\s+mes|cada\s+m\S*s|todo\s+dia|cada\s+ano|todo\s+ano|diariamente|diario|semanal|amanha|amanhã|hoje)\s*/iu', '', $title) ?? $title;
        $title = preg_replace('/\b(?:anual|anualmente|todo\s+ano|cada\s+ano|semanal|diario|diariamente|todo\s+dia|cada\s+dia|todos\s+os\s+dias)\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\b(?:em|daqui\s+a)\s+\d{1,3}\s+dias?\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\b(?:mes\s+que\s+vem|proximo\s+mes)\s+dia\s+\d{1,2}\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\bdia\s+\d{1,2}\s+(?:(?:do|de|no|na)\s+)?(?:mes\s+que\s+vem|proximo\s+mes)\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\bdia\s+\d{1,2}\s+(?:do|de|no|na)\s+mes\s+\d{1,2}\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\bdia\s+\d{1,2}\s+do\s+mes\s+de\s+[[:alpha:]]+\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\bmes\s+\d{1,2}\s+(?:dia\s+)?\d{1,2}\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\bdia\s+\d{1,2}(?:\/\d{1,2}(?:\/\d{4})?)?\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\bdia\s+\d{1,2}\s+(?:desse|deste)\s+m\S*s\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\bdia\s+\d{1,2}\s+de\s+[[:alpha:]]+\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\b(?:aniversario|aniversário|niver)\s+(?:de|da|do)?\s*/iu', '', $title) ?? $title;
        $title = preg_replace('/\b(?:desse|deste)\s+m\S*s\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\bmes\s+que\s+vem\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\bproximo\s+mes\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\b(todo|cada)\s+m\S*s\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\bmensal\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\b(?:segunda|terca|quarta|quinta|sexta|sabado|domingo)(?:-feira)?\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\bfeira\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\b(?:as|às)\s*\d{1,2}(?::\d{2})?\s*h?\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\b(?:a|à)\s+\d{1,2}(?::\d{2})?\s*h?\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\b\d{1,2}h(?:\d{2})?\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\b(?:me\s+lembra(?:r)?(?:\s+de)?|me\s+lembre(?:\s+de)?|lembrete(?:\s+para)?|lembrar\s+de|lembra\s+de)\b/iu', '', $title) ?? $title;
        $title = preg_replace('/\b(?:amanha|amanhã|hoje)\b/iu', '', $title) ?? $title;
        $title = preg_replace('/^\s*(?:todo|toda|cada)\s+/iu', '', $title) ?? $title;
        $title = preg_replace('/^\s*(?:(?:no|na|do|da|de)\s+)+/iu', '', $title) ?? $title;
        $title = preg_replace('/\s+/u', ' ', $title) ?? $title;
        $title = trim($title, " \t\n\r\0\x0B,.;:-?");

        if ($title === '') {
            return null;
        }

        return Str::title($title);
    }

    private function buildReminderMessage(string $originalClean, string $cleanedTitle, ?string $frequency): string
    {
        $templateType = ReminderMessageTemplateFactory::detect($originalClean, $originalClean);

        return ReminderMessageTemplateFactory::buildFriendlyMessage($cleanedTitle, $frequency ?? 'once', $templateType);
    }

    private function extractTriggerTime(string $clean, string $normalized): ?string
    {
        if (preg_match('/\b(\d{1,2}):(\d{2})\b/u', $normalized, $matches)) {
            return $this->formatTime((int) $matches[1], (int) $matches[2]);
        }

        if (preg_match('/\b(\d{1,2})h(?:\s*(\d{2}))?\b/u', $normalized, $matches)) {
            return $this->formatTime((int) $matches[1], isset($matches[2]) ? (int) $matches[2] : 0);
        }

        if (preg_match('/\b(?:as|a)\s*(\d{1,2})(?::(\d{2}))?\s*h?\b/iu', $clean, $matches)) {
            return $this->formatTime((int) $matches[1], isset($matches[2]) ? (int) $matches[2] : 0);
        }

        return null;
    }

    private function formatTime(int $hour, int $minute): ?string
    {
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d:00', $hour, $minute);
    }

    private function oneTimeThisMonthTrigger(int $day, string $time): Carbon
    {
        $date = now(config('app.timezone'))->copy()->startOfMonth()->day(min($day, now(config('app.timezone'))->daysInMonth));
        $this->applyTime($date, $time);

        if ($date->isPast()) {
            $date->addMonthNoOverflow()->day(min($day, $date->daysInMonth));
            $this->applyTime($date, $time);
        }

        return $date;
    }

    private function oneTimeNextMonthTrigger(int $day, string $time): Carbon
    {
        $date = now(config('app.timezone'))->copy()->addMonthNoOverflow()->startOfMonth();
        $date->day(min($day, $date->daysInMonth));
        $this->applyTime($date, $time);

        return $date;
    }

    private function oneTimeSpecificDateTrigger(int $day, int $month, int $year, string $time): Carbon
    {
        $date = Carbon::create($year, $month, 1, 0, 0, 0, config('app.timezone'));
        $date->day(min($day, $date->daysInMonth));
        $this->applyTime($date, $time);

        return $date;
    }

    private function onceTodayTrigger(string $time): Carbon
    {
        $date = now(config('app.timezone'))->copy();
        $this->applyTime($date, $time);

        if ($date->isPast()) {
            $date->addDay();
            $this->applyTime($date, $time);
        }

        return $date;
    }

    private function onceTomorrowTrigger(string $time): Carbon
    {
        $date = now(config('app.timezone'))->copy()->addDay();
        $this->applyTime($date, $time);

        return $date;
    }

    private function onceInDaysTrigger(int $days, string $time): Carbon
    {
        $date = now(config('app.timezone'))->copy()->addDays($days);
        $this->applyTime($date, $time);

        return $date;
    }

    private function oneTimeThisYearTrigger(int $day, int $month, string $time): Carbon
    {
        $reference = now(config('app.timezone'));
        $candidate = Carbon::create($reference->year, $month, 1, 0, 0, 0, config('app.timezone'));
        $candidate->day(min($day, $candidate->daysInMonth));
        $this->applyTime($candidate, $time);

        if ($candidate->lt($reference)) {
            $candidate->addYear();
            $this->applyTime($candidate, $time);
        }

        return $candidate;
    }

    private function nextDailyTrigger(string $time): Carbon
    {
        $reference = now(config('app.timezone'));
        $candidate = $reference->copy();
        $this->applyTime($candidate, $time);

        if ($candidate->lte($reference)) {
            $candidate->addDay();
            $this->applyTime($candidate, $time);
        }

        return $candidate;
    }

    private function nextWeeklyTrigger(int $dayOfWeek, string $time): Carbon
    {
        $reference = now(config('app.timezone'));
        $candidate = $reference->copy();
        $this->applyTime($candidate, $time);

        if ($candidate->dayOfWeek !== $dayOfWeek) {
            $candidate = $candidate->next($dayOfWeek);
            $this->applyTime($candidate, $time);
        } elseif ($candidate->lte($reference)) {
            $candidate = $candidate->addWeek();
            $this->applyTime($candidate, $time);
        }

        return $candidate;
    }

    private function nextMonthlyTrigger(int $day, string $time): Carbon
    {
        $reference = now(config('app.timezone'));
        $candidate = $reference->copy()->startOfMonth()->day(min($day, $reference->daysInMonth));
        $this->applyTime($candidate, $time);

        if ($candidate->lte($reference)) {
            $candidate = $reference->copy()->addMonthNoOverflow()->startOfMonth();
            $candidate->day(min($day, $candidate->daysInMonth));
            $this->applyTime($candidate, $time);
        }

        return $candidate;
    }

    private function nextYearlyTrigger(int $day, int $month, string $time): Carbon
    {
        $reference = now(config('app.timezone'));
        $candidate = Carbon::create($reference->year, $month, 1, 0, 0, 0, config('app.timezone'));
        $candidate->day(min($day, $candidate->daysInMonth));
        $this->applyTime($candidate, $time);

        if ($candidate->lte($reference)) {
            $candidate = Carbon::create($reference->year + 1, $month, 1, 0, 0, 0, config('app.timezone'));
            $candidate->day(min($day, $candidate->daysInMonth));
            $this->applyTime($candidate, $time);
        }

        return $candidate;
    }

    private function extractWeekday(string $normalized): ?int
    {
        foreach ([
            'domingo' => 0,
            'segunda-feira' => 1,
            'segunda' => 1,
            'terca-feira' => 2,
            'terca' => 2,
            'quarta-feira' => 3,
            'quarta' => 3,
            'quinta-feira' => 4,
            'quinta' => 4,
            'sexta-feira' => 5,
            'sexta' => 5,
            'sabado' => 6,
        ] as $name => $value) {
            if (str_contains($normalized, $name)) {
                return $value;
            }
        }

        return null;
    }

    private function applyTime(Carbon $date, string $time): void
    {
        try {
            $parts = explode(':', $time);
            if (count($parts) < 2) {
                $date->setTime(9, 0, 0);
                return;
            }

            $hour = (int) $parts[0];
            $minute = (int) $parts[1];
            $second = isset($parts[2]) ? (int) $parts[2] : 0;

            if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59) {
                $date->setTime(9, 0, 0);
                return;
            }

            $date->setTime($hour, $minute, $second);
        } catch (\Throwable $e) {
            $date->setTime(9, 0, 0);
        }
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
