<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Reminder;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\IncomingMessageNormalizer;
use App\Services\WhatsApp\ReminderMessageParser;

class EditReminderHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return $action === 'edit_reminder';
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        $reminders = $user->reminders()
            ->where('is_active', true)
            ->get();

        if ($reminders->isEmpty()) {
            $this->sendErrorMessage($job, 'Voce nao tem lembretes ativos para editar.');
            return true;
        }

        $parser = app(ReminderMessageParser::class);
        $reminderData = is_array($result['reminder_data'] ?? null) ? $result['reminder_data'] : [];
        $title = trim((string) ($reminderData['current_title'] ?? '')) ?: $this->extractReminderTitleFromMessage($job->message);
        $resolvedReminderId = (int) ($reminderData['reminder_id'] ?? 0);

        if ($title === null && $resolvedReminderId <= 0) {
            if ($reminders->count() === 1) {
                $reminder = $reminders->first();
                $this->sendErrorMessage(
                    $job,
                    "Diga como quer editar o lembrete '{$reminder->title}'. Por exemplo: editar lembrete {$reminder->title} para amanha as 10:00."
                );
                return true;
            }

            $this->sendErrorMessage($job, "Qual lembrete voce quer editar?\n\n{$this->renderReminderOptions($reminders)}");
            return true;
        }

        $matches = $resolvedReminderId > 0
            ? $reminders->where('id', $resolvedReminderId)
            : $this->findMatchingReminders($reminders, $title);

        if ($matches->count() === 0) {
            $this->sendErrorMessage($job, "Nao encontrei nenhum lembrete com o nome '{$title}'.\n\n{$this->renderReminderOptions($reminders)}");
            return true;
        }

        if ($matches->count() > 1) {
            $this->sendErrorMessage($job, "Existem varios lembretes parecidos com '{$title}'. Qual deles voce quer editar?\n\n{$this->renderReminderOptions($matches)}");
            return true;
        }

        /** @var Reminder $reminder */
        $reminder = $matches->first();

        $parsed = $reminderData !== []
            ? array_merge(['current_title' => $title], $reminderData)
            : ($parser->parsePartialCreate($this->normalizeForParsing($job->message)) ?? null);
        $updates = [];

        if ($parsed !== null) {
            if (! empty($parsed['title']) && $parsed['title'] !== $reminder->title) {
                // Ignore parsed titles that include explicit dates or numbers (common when user says "para 25/05").
                if (! preg_match('/[0-9\\/]/', $parsed['title'])) {
                    $updates['title'] = $parsed['title'];
                }
            }

            if (! empty($parsed['frequency'])) {
                $updates['frequency'] = $parsed['frequency'];
                $updates['day_of_week'] = $parsed['day_of_week'];
                $updates['day_of_month'] = $parsed['day_of_month'];
                $updates['month_of_year'] = $parsed['month_of_year'];
                $updates['next_trigger_at'] = $parsed['next_trigger_at'];
                $updates['trigger_time'] = $parsed['trigger_time'];
            }

            if (isset($parsed['trigger_time']) && $parsed['trigger_time'] !== $reminder->trigger_time) {
                $updates['trigger_time'] = $parsed['trigger_time'];
            }
        }

        if ($updates === []) {
            $this->sendErrorMessage(
                $job,
                "Nao consegui identificar o que voce quer editar no lembrete '{$reminder->title}'. Envie algo como:\n\neditar lembrete {$reminder->title} para amanha as 10:00"
            );
            return true;
        }

        if (! isset($updates['message'])) {
            $newTitle = $updates['title'] ?? $reminder->title;
            $newFrequency = $updates['frequency'] ?? $reminder->frequency;
            $updates['message'] = $parser->buildMessage($newTitle, $newFrequency);
        }

        $before = $reminder->only([
            'title',
            'message',
            'frequency',
            'timezone',
            'next_trigger_at',
            'day_of_week',
            'day_of_month',
            'month_of_year',
            'trigger_time',
            'metadata',
            'is_active',
        ]);

        $reminder->update($updates);

        $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
            'reply_kind' => 'action',
            'undo' => [
                'kind' => 'reminder_update',
                'id' => $reminder->id,
                'before' => $before,
                'expires_at' => now()->addSeconds(60)->toIso8601String(),
            ],
            'entities' => [
                'topic' => 'reminders',
                'reminder_id' => $reminder->id,
                'reminder_title' => $reminder->title,
                'frequency' => $reminder->frequency,
            ],
        ]);

        $this->sendResponse($job, "Lembrete '{$reminder->title}' atualizado com sucesso.", $user);
        return true;
    }

    private function normalizeForParsing(string $message): string
    {
        return (string) (preg_replace('/\\b(?:editar|edita|alterar|altera|modificar|modifica|mudar|muda)\\b/iu', '', $message) ?? $message);
    }

    private function extractReminderTitleFromMessage(string $message): ?string
    {
        $subject = preg_replace('/\\b(?:editar|edita|alterar|altera|modificar|modifica|mudar|muda)\\b/iu', '', $message) ?? $message;
        $subject = preg_replace('/\\b(?:o|a|os|as|um|uma|meu|minha|esse|essa|este|esta)\\b/iu', ' ', $subject) ?? $subject;
        $subject = preg_replace('/\\b(?:lembrete|lembretes|lembre)\\b/iu', ' ', $subject) ?? $subject;

        // Remove explicit dates and times to avoid polluting the title.
        $subject = preg_replace('/\\b\\d{1,2}\\/\\d{1,2}(?:\\/\\d{2,4})?\\b/u', ' ', $subject) ?? $subject;
        $subject = preg_replace('/\\b(?:as)\\b/iu', ' ', $subject) ?? $subject;
        $subject = preg_replace('/\\b\\d{1,2}:\\d{2}\\b/u', ' ', $subject) ?? $subject;

        // Remove common linking words that may precede dates/times.
        $subject = preg_replace('/\\b(?:para|pra|p\\/|pro|no|na|em)\\b/iu', ' ', $subject) ?? $subject;

        $subject = trim(preg_replace('/\\s+/u', ' ', $subject) ?? $subject);

        if ($subject === '') {
            return null;
        }

        return app(ReminderMessageParser::class)->extractTitle($subject);
    }

    /**
     * @param \Illuminate\Support\Collection<int, Reminder> $reminders
     */
    private function findMatchingReminders(\Illuminate\Support\Collection $reminders, string $title)
    {
        $normalizer = app(IncomingMessageNormalizer::class);
        $search = $normalizer->normalize($title);

        $exact = $reminders->filter(fn ($reminder) => $normalizer->normalize($reminder->title) === $search);
        if ($exact->isNotEmpty()) {
            return $exact;
        }

        return $reminders->filter(fn ($reminder) => str_contains($normalizer->normalize($reminder->title), $search));
    }

    private function renderReminderOptions($reminders): string
    {
        return $reminders->values()->map(fn ($reminder, $index) => ($index + 1).". {$reminder->title}")->implode("\n");
    }
}
