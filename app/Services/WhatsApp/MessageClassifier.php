<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppContact;

class MessageClassifier
{
    public function classify(string $message, array $state = []): array
    {
        $normalized = mb_strtolower(trim($message));
        $normalized = preg_replace('/\s+/u', ' ', $normalized);
        $stripped = preg_replace('/[!?.]+/u', '', $normalized);

        if ($this->isGreeting($stripped)) {
            return ['kind' => 'greeting', 'normalized' => $stripped];
        }

        if ($this->isCancellation($stripped)) {
            return ['kind' => 'cancellation', 'normalized' => $stripped];
        }

        if ($this->isShortAcknowledgement($stripped)) {
            $kind = ($state['mode'] ?? 'idle') === 'awaiting_confirmation'
                ? 'confirmation'
                : 'acknowledgement';

            return ['kind' => $kind, 'normalized' => $stripped];
        }

        if ($followUp = $this->extractFollowUpBudgetCategory($stripped, $state)) {
            return ['kind' => 'budget_follow_up', 'category_name' => $followUp, 'normalized' => $stripped];
        }

        return ['kind' => 'default', 'normalized' => $stripped];
    }

    private function isGreeting(string $message): bool
    {
        return in_array($message, ['oi', 'olá', 'ola', 'bom dia', 'boa tarde', 'boa noite', 'e ai', 'e aí', 'hey', 'opa'], true);
    }

    private function isShortAcknowledgement(string $message): bool
    {
        return in_array($message, ['ok', 'okay', 'blz', 'beleza', 'certo', 'sim', 'claro', 'pode', 'pode sim', 'perfeito', 'entendi', 'isso'], true);
    }

    private function isCancellation(string $message): bool
    {
        return in_array($message, ['cancelar', 'cancela', 'deixa', 'deixa pra la', 'deixa pra lá', 'não', 'nao'], true);
    }

    private function extractFollowUpBudgetCategory(string $message, array $state): ?string
    {
        if (($state['last_action'] ?? null) !== 'query_budgets') {
            return null;
        }

        if (!preg_match('/^(?:e\s+)?(?:o|a|os|as)?\s*([\p{L}\p{N} _-]+)$/u', $message, $matches)) {
            return null;
        }

        $term = trim($matches[1] ?? '');
        $term = preg_replace('/\b(orcamento|orçamento|mes|mês|geral|gerais|tambem|também)\b/u', '', $term);
        $term = trim((string) $term);

        return $term !== '' ? ucfirst($term) : null;
    }
}
