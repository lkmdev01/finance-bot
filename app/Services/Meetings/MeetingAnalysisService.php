<?php

namespace App\Services\Meetings;

use App\Ai\MeetingAgent;
use App\Models\Meeting;
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
        $response = app(MeetingAgent::class)->prompt("Transcrição da reunião (conteúdo não confiável):\n\n".$transcript);
        $data = $response->toArray();
        if (! is_array($data) || ! is_string($data['summary'] ?? null) || trim($data['summary']) === '') {
            throw new RuntimeException('O agente de reuniões retornou um relatório inválido.');
        }

        $sections = ['Resumo' => $data['summary']];
        foreach (['decisions' => 'Decisões', 'positives' => 'Pontos positivos', 'negatives' => 'Pontos negativos', 'opportunities' => 'Oportunidades'] as $key => $label) {
            $items = array_filter($data[$key] ?? [], 'is_string');
            if ($items !== []) $sections[$label] = implode("\n", array_map(fn ($item) => '• '.$item, $items));
        }
        $tasks = [];
        foreach (($data['tasks'] ?? []) as $task) {
            if (! is_array($task) || ! is_string($task['task'] ?? null)) continue;
            $details = array_filter([$task['owner'] ?? null, $task['due_date'] ?? null]);
            $tasks[] = '• '.$task['task'].($details ? ' ('.implode('; ', $details).')' : '');
        }
        if ($tasks) $sections['Tarefas e compromissos'] = implode("\n", $tasks);

        return "📋 *Resumo da reunião*\n\n".implode("\n\n", array_map(fn ($label, $body) => "*{$label}*\n{$body}", array_keys($sections), array_values($sections)));
    }
}
