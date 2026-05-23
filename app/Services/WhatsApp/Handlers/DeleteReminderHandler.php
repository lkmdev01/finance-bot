<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\User;
use App\Models\WhatsAppContact;

class DeleteReminderHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return $action === 'reminder_delete';
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        $normalized = $result['normalized'] ?? '';

        if ($this->containsText($normalized, ['todos', 'tudo', 'todas', 'todos os'])) {
            $this->deleteAllReminders($user, $job);
            return true;
        }

        $reminders = $user->reminders()
            ->where('is_active', true)
            ->get();

        if ($reminders->isEmpty()) {
            $this->sendErrorMessage($job, 'Voce nao tem lembretes ativos para apagar.');
            return true;
        }

        if ($reminders->count() === 1) {
            $reminder = $reminders->first();
            $reminder->delete();
            $this->sendResponse($job, "✅ Lembrete '{$reminder->title}' apagado com sucesso!", $user);
            return true;
        }

        $options = $reminders->map(function ($r, $i) {
            return ($i + 1) . ". {$r->title}";
        })->implode("\n");

        $this->sendErrorMessage($job, "Voce tem varios lembretes. Qual deles voce quer apagar?\n\n{$options}");
        return true;
    }

    private function deleteAllReminders(User $user, ProcessWhatsAppMessage $job): void
    {
        $count = $user->reminders()
            ->where('is_active', true)
            ->update(['is_active' => false]);

        if ($count === 0) {
            $this->sendErrorMessage($job, 'Voce nao tem lembretes ativos.');
            return;
        }

        $this->sendErrorMessage($job, "✅ Todos os {$count} lembrete(s) foram apagados!");
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
}

