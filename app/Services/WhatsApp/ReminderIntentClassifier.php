<?php

namespace App\Services\WhatsApp;

use App\Services\WhatsApp\Support\NormalizesWhatsAppText;

class ReminderIntentClassifier
{
    use NormalizesWhatsAppText;

    public function __construct(
        private readonly ReminderMessageParser $reminderMessageParser,
    ) {}

    public function classify(string $originalMessage, string $normalizedMessage, array $state): ?array
    {
        if ($this->looksLikeReminderCreate($originalMessage, $normalizedMessage)) {
            return ['kind' => 'reminder_create', 'normalized' => $normalizedMessage];
        }

        if ($this->looksLikeReminderMissingSchedule($originalMessage, $normalizedMessage)) {
            return [
                'kind' => 'reminder_needs_schedule',
                'normalized' => $normalizedMessage,
                'payload' => $this->reminderMessageParser->parsePartialCreate($originalMessage) ?? [],
            ];
        }

        if ($this->looksLikeReminderQuery($normalizedMessage, $state)) {
            return ['kind' => 'reminder_query', 'normalized' => $normalizedMessage];
        }

        return null;
    }

    private function looksLikeReminderCreate(string $originalMessage, string $normalizedMessage): bool
    {
        return $this->reminderMessageParser->looksLikeCreateIntent($normalizedMessage)
            && $this->reminderMessageParser->parse($originalMessage) !== null;
    }

    private function looksLikeReminderMissingSchedule(string $originalMessage, string $normalizedMessage): bool
    {
        $partial = $this->reminderMessageParser->parsePartialCreate($originalMessage);

        return $this->reminderMessageParser->looksLikeCreateIntent($normalizedMessage)
            && $partial !== null
            && empty($partial['frequency']);
    }

    private function looksLikeReminderQuery(string $normalizedMessage, array $state): bool
    {
        if ($this->containsAnyText($normalizedMessage, ['lembrete', 'lembretes', 'meus lembretes'])) {
            return true;
        }

        return ($state['last_action'] ?? null) === 'query_reminders'
            && $this->containsAnyText($normalizedMessage, ['ativos', 'ativo', 'de hoje', 'de amanha']);
    }
}
