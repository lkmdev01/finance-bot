<?php

namespace App\Services\WhatsApp;

use App\Services\WhatsApp\Support\NormalizesWhatsAppText;

class MessageClassifier
{
    use NormalizesWhatsAppText;

    public function __construct(
        private readonly BudgetIntentClassifier $budgetIntentClassifier,
        private readonly PlanningIntentClassifier $planningIntentClassifier,
        private readonly TransactionIntentClassifier $transactionIntentClassifier,
    ) {}

    public function classify(string $message, array $state = []): array
    {
        $normalized = $this->normalizeText($message);
        $stripped = preg_replace('/[!?.]+/u', '', $normalized) ?? $normalized;

        if ($this->isGreeting($stripped)) {
            return ['kind' => 'greeting', 'normalized' => $stripped];
        }

        foreach ([
            $this->transactionIntentClassifier,
            $this->planningIntentClassifier,
            $this->budgetIntentClassifier,
        ] as $classifier) {
            $result = $classifier->classify($message, $stripped, $state);
            if ($result !== null) {
                return $result;
            }
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

        return ['kind' => 'default', 'normalized' => $stripped];
    }

    private function isGreeting(string $message): bool
    {
        return in_array($message, ['oi', 'ola', 'bom dia', 'boa tarde', 'boa noite', 'e ai', 'hey', 'opa'], true);
    }

    private function isShortAcknowledgement(string $message): bool
    {
        return in_array($message, ['ok', 'okay', 'blz', 'beleza', 'certo', 'sim', 'claro', 'pode', 'pode sim', 'perfeito', 'entendi', 'isso'], true);
    }

    private function isCancellation(string $message): bool
    {
        if (in_array($message, ['cancelar', 'cancela', 'deixa', 'deixa pra la', 'nao'], true)) {
            return true;
        }

        foreach (['nao quero', 'agora nao', 'nao precisa'] as $phrase) {
            if ($message === $phrase || str_starts_with($message, $phrase . ' ')) {
                return true;
            }
        }

        return false;
    }
}
