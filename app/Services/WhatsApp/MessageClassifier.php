<?php

namespace App\Services\WhatsApp;

use App\Services\WhatsApp\Support\NormalizesWhatsAppText;

class MessageClassifier
{
    use NormalizesWhatsAppText;

    public function __construct(
        private readonly DomainGate $domainGate,
        private readonly ReminderIntentClassifier $reminderIntentClassifier,
        private readonly DriveIntentClassifier $driveIntentClassifier,
        private readonly NoteIntentClassifier $noteIntentClassifier,
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

        if ($this->isHelp($stripped)) {
            return ['kind' => 'help', 'normalized' => $stripped];
        }

        if ($this->isSmallTalk($stripped)) {
            return ['kind' => 'small_talk', 'normalized' => $stripped];
        }

        if ($this->isGratitude($stripped)) {
            return ['kind' => 'gratitude', 'normalized' => $stripped];
        }

        if ($this->isUndo($stripped)) {
            return ['kind' => 'undo', 'normalized' => $stripped];
        }

        $domain = $this->domainGate->detect($message, $state);

        foreach ($this->classifiersForDomain($domain) as $classifier) {
            $result = $classifier->classify($message, $stripped, $state);
            if ($result !== null) {
                $result['domain'] = $domain;

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

    private function classifiersForDomain(string $domain): array
    {
        return match ($domain) {
            'reminder' => [$this->reminderIntentClassifier],
            'drive' => [$this->driveIntentClassifier],
            'notes' => [$this->noteIntentClassifier],
            'transaction' => [$this->transactionIntentClassifier],
            'planning' => [$this->planningIntentClassifier],
            'budget' => [$this->budgetIntentClassifier],
            default => [
                $this->planningIntentClassifier,
                $this->transactionIntentClassifier,
                $this->noteIntentClassifier,
                $this->reminderIntentClassifier,
                $this->driveIntentClassifier,
                $this->budgetIntentClassifier,
            ],
        };
    }

    private function isGreeting(string $message): bool
    {
        return in_array($message, ['oi', 'ola', 'bom dia', 'boa tarde', 'boa noite', 'e ai', 'hey', 'opa'], true);
    }

    private function isShortAcknowledgement(string $message): bool
    {
        return in_array($message, ['ok', 'okay', 'blz', 'beleza', 'certo', 'sim', 'claro', 'pode', 'pode sim', 'perfeito', 'entendi', 'isso'], true);
    }

    private function isHelp(string $message): bool
    {
        foreach ([
            'ajuda',
            'me ajuda',
            'como voce pode me ajudar',
            'como vc pode me ajudar',
            'como pode me ajudar',
            'o que voce faz',
            'o que vc faz',
            'o que voce pode fazer',
            'o que vc pode fazer',
            'quais comandos voce entende',
            'como funciona',
        ] as $phrase) {
            if ($message === $phrase || str_starts_with($message, $phrase.' ')) {
                return true;
            }
        }

        return false;
    }

    private function isSmallTalk(string $message): bool
    {
        foreach ([
            'como voce esta',
            'como vc esta',
            'como vai',
            'tudo bem',
            'ta ai',
            'esta ai',
        ] as $phrase) {
            if ($message === $phrase || str_starts_with($message, $phrase.' ')) {
                return true;
            }
        }

        return false;
    }

    private function isGratitude(string $message): bool
    {
        foreach ([
            'obrigado',
            'obrigada',
            'obrigado viu',
            'obrigada viu',
            'valeu',
            'valeu mesmo',
            'show',
            'top',
            'perfeito obrigado',
            'perfeito obrigada',
        ] as $phrase) {
            if ($message === $phrase || str_starts_with($message, $phrase.' ')) {
                return true;
            }
        }

        return false;
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

    private function isUndo(string $message): bool
    {
        if (in_array($message, ['undo', 'desfazer', 'desfaz', 'desfaca', 'anular', 'voltar', 'volta'], true)) {
            return true;
        }

        foreach ([
            'desfaz isso',
            'desfaz o ultimo',
            'desfaz a ultima',
            'desfazer o ultimo',
            'desfazer a ultima',
            'anula isso',
            'volta isso',
        ] as $phrase) {
            if ($message === $phrase || str_starts_with($message, $phrase.' ')) {
                return true;
            }
        }

        return false;
    }
}
