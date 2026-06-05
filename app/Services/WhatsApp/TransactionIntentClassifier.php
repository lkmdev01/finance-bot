<?php

namespace App\Services\WhatsApp;

use App\Services\WhatsApp\Support\NormalizesWhatsAppText;

class TransactionIntentClassifier
{
    use NormalizesWhatsAppText;

    public function __construct(
        private readonly TransactionActionMessageParser $transactionActionMessageParser,
        private readonly RecurringTransactionMessageParser $recurringTransactionMessageParser,
        private readonly InstallmentTransactionMessageParser $installmentTransactionMessageParser,
        private readonly TransactionSplitMessageParser $transactionSplitMessageParser,
        private readonly SimpleTransactionMessageParser $simpleTransactionMessageParser,
    ) {}

    public function classify(string $originalMessage, string $normalizedMessage, array $state): ?array
    {
        if ($this->looksLikeRecurringTransactionEdit($originalMessage, $normalizedMessage, $state)) {
            return ['kind' => 'recurring_transaction_edit', 'normalized' => $normalizedMessage];
        }

        if ($this->looksLikeRecurringTransactionDelete($originalMessage, $normalizedMessage, $state)) {
            return ['kind' => 'recurring_transaction_delete', 'normalized' => $normalizedMessage];
        }

        if ($this->looksLikeTransactionSplit($normalizedMessage, $state)) {
            return ['kind' => 'transaction_split', 'normalized' => $normalizedMessage];
        }

        if ($this->looksLikeTransactionDelete($originalMessage, $normalizedMessage, $state)) {
            return ['kind' => 'transaction_delete', 'normalized' => $normalizedMessage];
        }

        if ($this->looksLikeTransactionEdit($originalMessage, $normalizedMessage, $state)) {
            return ['kind' => 'transaction_edit', 'normalized' => $normalizedMessage];
        }

        if ($this->looksLikeRecurringTransactionCreate($originalMessage, $normalizedMessage)) {
            return ['kind' => 'recurring_transaction_create', 'normalized' => $normalizedMessage];
        }

        if ($this->looksLikeRecurringTransactionMissingAmount($originalMessage, $normalizedMessage)) {
            return [
                'kind' => 'recurring_transaction_needs_amount',
                'normalized' => $normalizedMessage,
                'payload' => $this->recurringTransactionMessageParser->parsePartialCreate($originalMessage) ?? [],
            ];
        }

        if ($this->looksLikeInstallmentTransactionCreate($originalMessage, $normalizedMessage)) {
            return ['kind' => 'installment_transaction_create', 'normalized' => $normalizedMessage];
        }

        if ($this->looksLikeCompoundTransactionCreate($normalizedMessage)) {
            return ['kind' => 'compound_transaction_create', 'normalized' => $normalizedMessage];
        }

        if ($this->looksLikeSimpleTransactionCreate($originalMessage, $normalizedMessage)) {
            return ['kind' => 'transaction_create', 'normalized' => $normalizedMessage];
        }

        if ($this->looksLikeTransactionFollowUp($normalizedMessage, $state)) {
            return [
                'kind' => 'transaction_follow_up',
                'target_action' => $state['last_action'] ?? 'query_transactions',
                'normalized' => $normalizedMessage,
            ];
        }

        return null;
    }

    private function looksLikeTransactionEdit(string $originalMessage, string $normalizedMessage, array $state): bool
    {
        if (($state['last_entities']['topic'] ?? null) === 'recurring_transactions') {
            return false;
        }

        if ($this->containsBudgetCue($originalMessage, $normalizedMessage)) {
            return false;
        }

        if ($this->transactionActionMessageParser->looksLikeEditIntent($normalizedMessage)) {
            return $this->transactionActionMessageParser->parseEdit($originalMessage, $state) !== null;
        }

        if (! in_array($state['last_action'] ?? null, ['query_transactions', 'query_category'], true)) {
            return false;
        }

        if ($this->containsAnyText($normalizedMessage, ['debito', 'credito', 'pix'])
            && $this->containsAnyText($normalizedMessage, ['esse', 'essa', 'aquele', 'aquela', 'ultimo', 'ultima', 'ontem', 'hoje'])) {
            return true;
        }

        return $this->containsAnyText($normalizedMessage, ['ajusta', 'ajustar', 'corrige', 'corrigir', 'muda', 'mudar', 'edita', 'editar']);
    }

    private function looksLikeTransactionSplit(string $normalizedMessage, array $state): bool
    {
        if ($this->transactionSplitMessageParser->looksLikeSplitIntent($normalizedMessage)) {
            return true;
        }

        return in_array($state['last_action'] ?? null, ['query_transactions', 'query_category'], true)
            && $this->containsAnyText($normalizedMessage, ['divide', 'dividir', 'separa', 'separar']);
    }

    private function looksLikeTransactionDelete(string $originalMessage, string $normalizedMessage, array $state): bool
    {
        if (($state['last_entities']['topic'] ?? null) === 'recurring_transactions') {
            return false;
        }

        if ($this->containsBudgetCue($originalMessage, $normalizedMessage)) {
            return false;
        }

        if ($this->transactionActionMessageParser->looksLikeDeleteIntent($normalizedMessage)) {
            return $this->transactionActionMessageParser->parseDelete($originalMessage, $state) !== null;
        }

        if (! in_array($state['last_action'] ?? null, ['query_transactions', 'query_category'], true)) {
            return false;
        }

        return $this->containsAnyText($normalizedMessage, ['apaga', 'apagar', 'remove', 'remover', 'deleta', 'deletar', 'exclui', 'excluir']);
    }

    private function looksLikeTransactionFollowUp(string $message, array $state): bool
    {
        $lastAction = $state['last_action'] ?? null;

        if (! in_array($lastAction, ['query_transactions', 'query_category'], true)) {
            return false;
        }

        if ($this->containsAnyText($message, ['mes passado', 'esse mes', 'este mes', 'hoje', 'ontem'])) {
            return true;
        }

        if ($this->containsAnyText($message, ['compare', 'comparar', 'versus', 'vs', 'mais pesou', 'mais pesa'])) {
            return true;
        }

        return $this->looksLikeCategoryFollowUpMessage($message);
    }

    private function looksLikeRecurringTransactionCreate(string $originalMessage, string $normalizedMessage): bool
    {
        return $this->recurringTransactionMessageParser->looksLikeCreateIntent($normalizedMessage)
            && $this->recurringTransactionMessageParser->parse($originalMessage) !== null;
    }

    private function looksLikeRecurringTransactionMissingAmount(string $originalMessage, string $normalizedMessage): bool
    {
        $partial = $this->recurringTransactionMessageParser->parsePartialCreate($originalMessage);

        return $this->recurringTransactionMessageParser->looksLikeCreateIntent($normalizedMessage)
            && $partial !== null
            && ! array_key_exists('amount', $partial);
    }

    private function looksLikeRecurringTransactionEdit(string $originalMessage, string $normalizedMessage, array $state): bool
    {
        $fallback = ($state['last_entities']['topic'] ?? null) === 'recurring_transactions'
            ? ($state['last_entities']['recurring_description'] ?? null)
            : null;

        if ($this->recurringTransactionMessageParser->parseEdit($originalMessage, $fallback) !== null) {
            return true;
        }

        return $fallback !== null && $this->containsAnyText($normalizedMessage, ['editar', 'edita', 'alterar', 'altera', 'ajustar', 'ajusta', 'mudar', 'muda', 'atualizar', 'atualiza']);
    }

    private function looksLikeRecurringTransactionDelete(string $originalMessage, string $normalizedMessage, array $state): bool
    {
        $fallback = ($state['last_entities']['topic'] ?? null) === 'recurring_transactions'
            ? ($state['last_entities']['recurring_description'] ?? null)
            : null;

        if ($this->recurringTransactionMessageParser->parseCancel($originalMessage, $fallback) !== null) {
            return true;
        }

        return $fallback !== null && $this->containsAnyText($normalizedMessage, ['cancelar', 'cancela', 'desativar', 'desativa', 'parar', 'para', 'pausar', 'pausa']);
    }

    private function looksLikeInstallmentTransactionCreate(string $originalMessage, string $normalizedMessage): bool
    {
        return $this->installmentTransactionMessageParser->looksLikeCreateIntent($normalizedMessage)
            && $this->installmentTransactionMessageParser->parse($originalMessage) !== null;
    }

    private function looksLikeCompoundTransactionCreate(string $message): bool
    {
        if (! $this->containsAnyText($message, ['gastei', 'paguei', 'recebi', 'ganhei', 'entrou'])) {
            return false;
        }

        // Ignore absolute dates (e.g. 14/05/2026) so they don't look like "multiple amounts".
        $messageWithoutDates = preg_replace('/\\b\\d{1,2}\\/\\d{1,2}\\/\\d{2,4}\\b/u', '', $message) ?? $message;

        preg_match_all('/(?:r\\$\\s*)?\\d+(?:[\\.,]\\d{1,2})?/u', $messageWithoutDates, $amountMatches);
        if (count($amountMatches[0] ?? []) < 2) {
            return false;
        }

        return $this->containsAnyText($message, [' e ', ',', ';', ' depois ', ' tambem ', ' mais ']);
    }

    private function looksLikeSimpleTransactionCreate(string $originalMessage, string $normalizedMessage): bool
    {
        if ($this->containsBudgetCue($originalMessage, $normalizedMessage)) {
            return false;
        }

        if ($this->looksLikeRecurringTransactionCreate($originalMessage, $normalizedMessage)) {
            return false;
        }

        if ($this->looksLikeInstallmentTransactionCreate($originalMessage, $normalizedMessage)) {
            return false;
        }

        return $this->simpleTransactionMessageParser->looksLikeCreateIntent($normalizedMessage)
            && $this->simpleTransactionMessageParser->parse($originalMessage) !== null;
    }

    private function looksLikeCategoryFollowUpMessage(string $message): bool
    {
        if (! preg_match('/^(?:e\s+)?(?:o|a|os|as)?\s*([\p{L}\p{N} _-]+)$/u', $message, $matches)) {
            return false;
        }

        $term = trim($matches[1] ?? '');
        if ($term === '') {
            return false;
        }

        $wordCount = count(array_filter(explode(' ', $term)));

        return $wordCount <= 3 && ! $this->containsAnyText($term, ['saldo', 'orcamento', 'relatorio']);
    }

    private function containsBudgetCue(string $originalMessage, string $normalizedMessage): bool
    {
        return str_contains($normalizedMessage, 'orcamento')
            || str_contains($normalizedMessage, 'oramento')
            || preg_match('/or.{0,4}amento/iu', $originalMessage) === 1;
    }
}
