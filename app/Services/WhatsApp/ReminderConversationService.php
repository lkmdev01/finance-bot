<?php

namespace App\Services\WhatsApp;

use App\Models\User;

class ReminderConversationService
{
    public function buildReply(User $user, string $message, array $state): array
    {
        $reminders = $user->reminders()
            ->where('is_active', true)
            ->orderBy('next_trigger_at')
            ->limit(10)
            ->get();

        if ($reminders->isEmpty()) {
            return [
                'reply' => 'Voce ainda nao tem lembretes ativos. Se quiser, posso criar um para voce.',
                'entities' => ['topic' => 'reminders'],
            ];
        }

        $lines = $reminders->map(function ($reminder) {
            $nextDate = $reminder->next_trigger_at?->format('d/m/Y') ?? 'sem data';
            $time = $reminder->trigger_time ? substr($reminder->trigger_time, 0, 5) : '09:00';
            $frequency = match ($reminder->frequency) {
                'daily' => 'diario',
                'weekly' => 'semanal',
                'monthly' => 'mensal',
                'yearly' => 'anual',
                default => 'pontual',
            };

            return sprintf('- %s: %s, proximo em %s as %s', $reminder->title, $frequency, $nextDate, $time);
        })->implode("\n");

        return [
            'reply' => "Seus lembretes ativos:\n{$lines}",
            'entities' => [
                'topic' => 'reminders',
                'reminder_id' => $reminders->first()?->id,
                'reminder_title' => $reminders->first()?->title,
            ],
        ];
    }
}
