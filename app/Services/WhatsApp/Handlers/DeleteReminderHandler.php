<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\IncomingMessageNormalizer;
use App\Services\WhatsApp\ReminderMessageParser;
use Illuminate\Support\Facades\Log;

class DeleteReminderHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return $action === 'delete_reminder';
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

        $title = $this->extractReminderTitleFromMessage($job->message);
        if ($title !== null) {
            $matches = $this->findMatchingReminders($reminders, $title);

            if ($matches->count() === 1) {
                $reminder = $matches->first();
                $reminder->update(['is_active' => false]);
                $this->sendResponse($job, "✅ Lembrete '{$reminder->title}' apagado com sucesso!", $user);
                return true;
            }

            if ($matches->count() > 1) {
                $this->sendErrorMessage($job, "Existem varios lembretes parecidos com '{$title}'. Qual deles voce quer apagar?\n\n{$this->renderReminderOptions($matches)}");
                return true;
            }

            $this->sendErrorMessage($job, "Nao encontrei nenhum lembrete com o nome '{$title}'.\n\n{$this->renderReminderOptions($reminders)}");
            return true;
        }

        if ($reminders->count() === 1) {
            $reminder = $reminders->first();
            $reminder->update(['is_active' => false]);
            $this->sendResponse($job, "✅ Lembrete '{$reminder->title}' apagado com sucesso!", $user);
            return true;
        }

        $this->sendErrorMessage($job, "Voce tem varios lembretes. Qual deles voce quer apagar?\n\n{$this->renderReminderOptions($reminders)}");
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

        $this->sendResponse($job, "✅ Todos os {$count} lembrete(s) foram apagados!", $user);
    }

    private function extractReminderTitleFromMessage(string $message): ?string
    {
        $subject = preg_replace('/\b(?:apagar|apaga|apague|apaguei|deletar|deleta|remover|remove|excluir|exclui|cancelar|cancela|editar|edita|alterar|altera|modificar|modifica|mudar|muda)\b/iu', '', $message);
        $subject = preg_replace('/\b(?:o|a|os|as|um|uma|meu|minha|esse|essa|este|esta)\b/iu', ' ', $subject);
        $subject = preg_replace('/\b(?:lembrete|lembretes|lembre)\b/iu', ' ', $subject);
        $subject = trim(preg_replace('/\s+/u', ' ', $subject));

        if ($subject === '') {
            return null;
        }

        return app(ReminderMessageParser::class)->extractTitle($subject);
    }

    /**
     * @param \Illuminate\Support\Collection $reminders
     */
    private function findMatchingReminders(\Illuminate\Support\Collection $reminders, string $title)
    {
        $normalizer = app(IncomingMessageNormalizer::class);
        $search = $normalizer->normalize($title);

        $exactMatches = $reminders->filter(function ($reminder) use ($normalizer, $search) {
            return $normalizer->normalize($reminder->title) === $search;
        });

        if ($exactMatches->isNotEmpty()) {
            return $exactMatches;
        }

        return $reminders->filter(function ($reminder) use ($normalizer, $search) {
            return str_contains($normalizer->normalize($reminder->title), $search);
        });
    }

    private function renderReminderOptions($reminders): string
    {
        return $reminders->values()->map(function ($reminder, $index) {
            return ($index + 1) . ". {$reminder->title}";
        })->implode("\n");
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

