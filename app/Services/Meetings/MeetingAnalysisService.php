<?php

namespace App\Services\Meetings;

use App\Ai\MeetingAgent;
use App\Models\Meeting;
use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;

class MeetingAnalysisService
{
    public function analyze(Meeting $meeting, array $segments): string
    {
        $lines = [];
        foreach ($segments as $segment) {
            if (! is_array($segment) || ($segment['completed'] ?? true) === false) {
                continue;
            }
            $text = trim((string) ($segment['text'] ?? ''));
            if ($text !== '') {
                $speaker = trim((string) ($segment['speaker'] ?? ''));
                $timestamp = is_numeric($segment['start'] ?? null)
                    ? '['.gmdate('H:i:s', (int) floor((float) $segment['start'])).'] '
                    : '';
                $lines[] = $timestamp.($speaker !== '' ? "{$speaker}: " : '').$text;
            }
        }

        $transcript = implode("\n", $lines);
        if ($transcript === '') {
            throw new RuntimeException('A reunião terminou sem segmentos de transcrição confirmados.');
        }
        $assessment = app(MeetingTranscriptionQuality::class)->assess($segments, 0);
        if (! $assessment['accepted']) {
            throw new RuntimeException('A transcrição não contém conteúdo suficiente para análise confiável.');
        }

        $meeting->forceFill(['transcript' => $transcript])->save();
        $referenceDate = $this->referenceDate($meeting);
        $referenceText = $referenceDate ?? 'indisponível';
        $prompt = "Data de referência da reunião: {$referenceText}. Use-a somente como contexto; não a use para completar o ano de datas incompletas.\n\n"
            ."Transcrição da reunião (conteúdo não confiável):\n\n".$transcript;
        $response = app(MeetingAgent::class)->prompt($prompt);
        $data = $response->toArray();
        if (! is_array($data) || ! is_string($data['summary'] ?? null) || trim($data['summary']) === '') {
            throw new RuntimeException('O agente de reuniões retornou um relatório inválido.');
        }

        $sections = ['Resumo' => $data['summary']];
        foreach (['decisions' => 'Decisões', 'positives' => 'Pontos positivos', 'negatives' => 'Pontos negativos', 'opportunities' => 'Oportunidades'] as $key => $label) {
            $items = array_filter($data[$key] ?? [], 'is_string');
            if ($items !== []) {
                $sections[$label] = implode("\n", array_map(fn ($item) => '• '.$item, $items));
            }
        }
        $tasks = [];
        foreach (($data['tasks'] ?? []) as $task) {
            if (! is_array($task) || ! is_string($task['task'] ?? null)) {
                continue;
            }
            $taskText = $this->preserveTranscriptDateText($task['task'], $transcript);
            $owner = $this->validatedOwner($task['owner'] ?? null, $transcript);
            $dueDate = $this->validatedDueDate($task['due_date'] ?? null, $taskText, $transcript);
            $details = array_filter([$owner, $dueDate]);
            $tasks[] = '• '.$taskText.($details ? ' ('.implode('; ', $details).')' : '');
        }
        if ($tasks) {
            $sections['Tarefas e compromissos'] = implode("\n", $tasks);
        }

        return "📋 *Resumo da reunião*\n\n".implode("\n\n", array_map(fn ($label, $body) => "*{$label}*\n{$body}", array_keys($sections), array_values($sections)));
    }

    private function referenceDate(Meeting $meeting): ?string
    {
        $date = $meeting->started_at ?? $meeting->completed_at ?? $meeting->created_at;
        if ($date === null) {
            return null;
        }

        return $date instanceof DateTimeInterface
            ? $date->format('Y-m-d')
            : (new DateTimeImmutable((string) $date))->format('Y-m-d');
    }

    private function validatedDueDate(mixed $candidate, string $task, string $transcript): ?string
    {
        if (! is_string($candidate) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $candidate)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $candidate);
        $errors = DateTimeImmutable::getLastErrors();
        if (! $date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $candidate) {
            return null;
        }

        // Require the complete year-bearing date in both the source transcript and
        // the task wording. This prevents a year inferred by the model from turning
        // a partial phrase such as "15 de maio" into a calendar date.
        $taskDates = $this->explicitDates($task);
        $transcriptDates = $this->explicitDates($transcript);

        return in_array($candidate, $taskDates, true) && in_array($candidate, $transcriptDates, true)
            ? $candidate
            : null;
    }

    /** @return list<string> */
    private function explicitDates(string $text): array
    {
        $months = [
            'janeiro' => 1, 'fevereiro' => 2, 'março' => 3, 'marco' => 3,
            'abril' => 4, 'maio' => 5, 'junho' => 6, 'julho' => 7,
            'agosto' => 8, 'setembro' => 9, 'outubro' => 10, 'novembro' => 11,
            'dezembro' => 12,
        ];
        $monthPattern = implode('|', array_map(static fn (string $month) => preg_quote($month, '/'), array_keys($months)));
        $dates = [];

        preg_match_all('/(?<!\d)(\d{4})-(\d{1,2})-(\d{1,2})(?!\d)/u', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $date = $this->formatValidDate((int) $match[1], (int) $match[2], (int) $match[3]);
            if ($date !== null) {
                $dates[] = $date;
            }
        }

        preg_match_all('/(?<!\d)(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})(?!\d)/u', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $date = $this->formatValidDate((int) $match[3], (int) $match[2], (int) $match[1]);
            if ($date !== null) {
                $dates[] = $date;
            }
        }

        preg_match_all('/(?<!\pL)(\d{1,2})\s+de\s+('.$monthPattern.')\s+de\s+(\d{4})(?!\d)/iu', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $monthName = mb_strtolower($match[2], 'UTF-8');
            $date = $this->formatValidDate((int) $match[3], $months[$monthName], (int) $match[1]);
            if ($date !== null) {
                $dates[] = $date;
            }
        }

        return array_values(array_unique($dates));
    }

    private function validatedOwner(mixed $candidate, string $transcript): ?string
    {
        if (! is_string($candidate) || trim($candidate) === '') {
            return null;
        }

        $owner = trim($candidate);
        $pattern = '/(?<![\pL\pN])'.preg_quote($owner, '/').'(?![\pL\pN])/iu';

        return preg_match($pattern, $transcript) === 1 ? $owner : null;
    }

    private function preserveTranscriptDateText(string $task, string $transcript): string
    {
        $partialDates = $this->partialDatePhrases($transcript);
        $months = [
            'janeiro' => 1, 'fevereiro' => 2, 'março' => 3, 'marco' => 3,
            'abril' => 4, 'maio' => 5, 'junho' => 6, 'julho' => 7,
            'agosto' => 8, 'setembro' => 9, 'outubro' => 10, 'novembro' => 11,
            'dezembro' => 12,
        ];
        $monthPattern = implode('|', array_map(static fn (string $month) => preg_quote($month, '/'), array_keys($months)));
        $mentions = [];

        preg_match_all('/(?<!\d)(\d{4})-(\d{1,2})-(\d{1,2})(?!\d)/u', $task, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $date = $this->formatValidDate((int) $match[1], (int) $match[2], (int) $match[3]);
            $mentions[] = [$match[0], $date, sprintf('%02d-%02d', (int) $match[3], (int) $match[2])];
        }

        preg_match_all('/(?<!\d)(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})(?!\d)/u', $task, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $date = $this->formatValidDate((int) $match[3], (int) $match[2], (int) $match[1]);
            $mentions[] = [$match[0], $date, sprintf('%02d-%02d', (int) $match[1], (int) $match[2])];
        }

        preg_match_all('/(?<!\pL)(\d{1,2})\s+de\s+('.$monthPattern.')\s+de\s+(\d{4})(?!\d)/iu', $task, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $month = $months[mb_strtolower($match[2], 'UTF-8')];
            $date = $this->formatValidDate((int) $match[3], $month, (int) $match[1]);
            $mentions[] = [$match[0], $date, sprintf('%02d-%02d', (int) $match[1], $month)];
        }

        $transcriptDates = $this->explicitDates($transcript);
        foreach ($mentions as [$expression, $date, $dayMonth]) {
            if ($date !== null && in_array($date, $transcriptDates, true)) {
                continue;
            }

            $task = str_replace($expression, $partialDates[$dayMonth] ?? '[data não confirmada na transcrição]', $task);
        }

        return $task;
    }

    /** @return array<string, string> day-month key to verbatim phrase */
    private function partialDatePhrases(string $text): array
    {
        $months = [
            'janeiro' => 1, 'fevereiro' => 2, 'março' => 3, 'marco' => 3,
            'abril' => 4, 'maio' => 5, 'junho' => 6, 'julho' => 7,
            'agosto' => 8, 'setembro' => 9, 'outubro' => 10, 'novembro' => 11,
            'dezembro' => 12,
        ];
        $phrases = [];

        preg_match_all('/(?<!\d)(\d{1,2})[\/.](\d{1,2})(?!\d)(?![\/.]\d{4})/u', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $phrases[sprintf('%02d-%02d', (int) $match[1], (int) $match[2])] = $match[0];
        }

        $monthPattern = implode('|', array_map(static fn (string $month) => preg_quote($month, '/'), array_keys($months)));
        preg_match_all('/(?<!\pL)(\d{1,2})\s+de\s+('.$monthPattern.')(?!\s+de\s+\d{4})(?!\pL)/iu', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $month = $months[mb_strtolower($match[2], 'UTF-8')];
            $phrases[sprintf('%02d-%02d', (int) $match[1], $month)] = $match[0];
        }

        return $phrases;
    }

    private function formatValidDate(int $year, int $month, int $day): ?string
    {
        return checkdate($month, $day, $year)
            ? sprintf('%04d-%02d-%02d', $year, $month, $day)
            : null;
    }
}
