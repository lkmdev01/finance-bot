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
        // Delete deve vir ANTES de query para evitar false positives
        if ($this->looksLikeReminderDelete($normalizedMessage, $state)) {
            return ['kind' => 'reminder_delete', 'normalized' => $normalizedMessage];
        }

        // Query deve vir ANTES de create para evitar false positives
        if ($this->looksLikeReminderQuery($normalizedMessage, $state)) {
            return ['kind' => 'reminder_query', 'normalized' => $normalizedMessage];
        }

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

        if (! $this->reminderMessageParser->looksLikeCreateIntent($normalizedMessage) || $partial === null) {
            return false;
        }

        if (empty($partial['frequency'])) {
            return true;
        }

        if (($partial['frequency'] ?? null) === 'weekly' && ! isset($partial['day_of_week'])) {
            return true;
        }

        if (($partial['frequency'] ?? null) === 'monthly' && empty($partial['day_of_month'])) {
            return true;
        }

        if (($partial['frequency'] ?? null) === 'yearly' && (empty($partial['day_of_month']) || empty($partial['month_of_year']))) {
            return true;
        }

        if (($partial['frequency'] ?? null) === 'once' && empty($partial['next_trigger_at'])) {
            return true;
        }

        return false;
    }

    private function looksLikeReminderDelete(string $normalizedMessage, array $state): bool
    {
        $hasDeleteKeyword = $this->containsAnyText($normalizedMessage, ['apagar', 'apaga', 'deletar', 'deleta', 'remover', 'remove', 'excluir', 'exclui', 'cancelar', 'cancela']);
        $hasReminderKeyword = $this->containsAnyText($normalizedMessage, ['lembrete', 'lembretes', 'meu lembrete', 'meus lembretes']);

        return $hasDeleteKeyword && $hasReminderKeyword;
    }

    private function looksLikeReminderQuery(string $normalizedMessage, array $state): bool
    {
        if ($this->containsAnyText($normalizedMessage, ['quais', 'quais sao', 'quais são', 'liste', 'lista', 'listar', 'mostre', 'mostra', 'mostrar'])) {
            if ($this->containsAnyText($normalizedMessage, ['lembrete', 'lembretes', 'meus lembretes'])) {
                return true;
            }
        }

        if ($this->containsAnyText($normalizedMessage, ['lembrete', 'lembretes', 'meus lembretes'])) {
            return true;
        }

        return ($state['last_action'] ?? null) === 'query_reminders'
            && $this->containsAnyText($normalizedMessage, ['ativos', 'ativo', 'de hoje', 'de amanha']);
    }
}
