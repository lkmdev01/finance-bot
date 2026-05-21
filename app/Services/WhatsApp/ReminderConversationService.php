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

            return sprintf('- %s: proximo lembrete em %s', $reminder->title, $nextDate);
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
