<?php

namespace App\Services\WhatsApp\Handlers;

use App\Models\Reminder;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class DeleteReminderHandler implements ShouldQueue
{
    use Queueable;

    public function handle(array $action, User $user, $job): void
    {
        if ($action['kind'] !== 'reminder_delete') {
            return;
        }

        $normalized = $action['normalized'] ?? '';

        if ($this->containsText($normalized, ['todos', 'tudo', 'todas', 'todos os'])) {
            $this->deleteAllReminders($user, $job);
            return;
        }

        $reminders = $user->reminders()
            ->where('is_active', true)
            ->get();

        if ($reminders->isEmpty()) {
            $this->sendMessage($job, 'Voce nao tem lembretes ativos para apagar.');
            return;
        }

        if ($reminders->count() === 1) {
            $reminders->first()->delete();
            $this->sendMessage($job, "✅ Lembrete '{$reminders->first()->title}' apagado com sucesso!");
            return;
        }

        $options = $reminders->map(function ($r, $i) {
            return ($i + 1) . ". {$r->title}";
        })->implode("\n");

        $this->sendErrorMessage($job, "Voce tem varios lembretes. Qual deles voce quer apagar?\n\n{$options}");
    }

    private function deleteAllReminders(User $user, $job): void
    {
        $count = $user->reminders()
            ->where('is_active', true)
            ->update(['is_active' => false]);

        if ($count === 0) {
            $this->sendMessage($job, 'Voce nao tem lembretes ativos.');
            return;
        }

        $this->sendMessage($job, "✅ Todos os {$count} lembrete(s) foram apagados!");
    }

    private function containsText(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains(strtolower($text), strtolower($keyword))) {
                return true;
            }
        }
        return false;
    }

    private function sendMessage($job, string $message): void
    {
        $job->message = $message;
    }

    private function sendErrorMessage($job, string $message): void
    {
        $job->message = $message;
    }
}
