<?php

namespace App\Services\WhatsApp\Resolvers;

use App\Services\WhatsApp\TransactionActionMessageParser;
use App\Services\WhatsApp\TransactionSplitMessageParser;

class ClarificationResolver
{
    public function __construct(
        private readonly TransactionActionMessageParser $transactionActionMessageParser,
        private readonly TransactionSplitMessageParser $transactionSplitMessageParser,
    ) {}

    public function shouldResolve(string $classification, array $state): bool
    {
        return match ($state['pending_intent'] ?? null) {
            'update_budget_category', 'delete_budget_category' => in_array($classification, ['default', 'budget_query'], true),
            'edit_transaction_details' => in_array($classification, ['default', 'transaction_edit'], true),
            'split_transaction_details' => in_array($classification, ['default', 'transaction_split'], true),
            'create_recurring_transaction_amount' => in_array($classification, ['default', 'transaction_create'], true),
            default => false,
        };
    }

    public function resolve(string $message, array $state): ?array
    {
        return match ($state['pending_intent'] ?? null) {
            'update_budget_category' => $this->buildBudgetClarificationResult('update_budget', $message, $state),
            'delete_budget_category' => $this->buildBudgetClarificationResult('delete_budget', $message, $state),
            'edit_transaction_details' => $this->buildTransactionEditClarificationResult($message, $state),
            'split_transaction_details' => $this->buildTransactionSplitClarificationResult($message, $state),
            'create_recurring_transaction_amount' => $this->buildRecurringAmountClarificationResult($message, $state),
            default => null,
        };
    }

    private function buildBudgetClarificationResult(string $action, string $message, array $state): ?array
    {
        $categoryName = trim($message, " \t\n\r\0\x0B .,:;!?");
        if ($categoryName === '') {
            return null;
        }

        $budgetData = $state['pending_payload']['budget_data'] ?? [];
        $budgetData['category_name'] = $categoryName;

        return [
            'handled' => false,
            'result' => [
                'reply' => '',
                'action' => $action,
                'budget_data' => $budgetData,
                '_resolved_message' => $message,
                '_conversation_metadata' => [
                    'clear_pending' => true,
                    'reply_kind' => 'action',
                ],
            ],
        ];
    }

    private function buildTransactionEditClarificationResult(string $message, array $state): ?array
    {
        $transactionData = $this->transactionActionMessageParser->parseEdit($message, $state) ?? [];
        $pending = $state['pending_payload']['transaction_data'] ?? [];

        if ($transactionData === [] && empty($pending['transaction_id'])) {
            return null;
        }

        return [
            'handled' => false,
            'result' => [
                'reply' => '',
                'action' => 'edit_transaction',
                'transaction_data' => array_merge($pending, $transactionData),
                '_resolved_message' => $message,
                '_conversation_metadata' => [
                    'clear_pending' => true,
                    'reply_kind' => 'action',
                ],
            ],
        ];
    }

    private function buildTransactionSplitClarificationResult(string $message, array $state): ?array
    {
        $parsed = $this->transactionSplitMessageParser->parse('divide em categorias '.$message) ?? [];
        $pending = $state['pending_payload']['transaction_data'] ?? [];

        if (empty($parsed['split_items'])) {
            return null;
        }

        return [
            'handled' => false,
            'result' => [
                'reply' => '',
                'action' => 'split_transaction',
                'transaction_data' => array_merge($pending, $parsed),
                '_resolved_message' => $message,
                '_conversation_metadata' => [
                    'clear_pending' => true,
                    'reply_kind' => 'action',
                ],
            ],
        ];
    }

    private function buildRecurringAmountClarificationResult(string $message, array $state): ?array
    {
        $amount = $this->extractAmount($message);
        $pending = $state['pending_payload']['recurring_data'] ?? [];

        if ($amount === null || empty($pending['description']) || empty($pending['frequency'])) {
            return null;
        }

        $pending['amount'] = $amount;

        return [
            'handled' => false,
            'result' => [
                'reply' => '',
                'action' => 'create_recurring_transaction',
                'recurring_data' => $pending,
                '_resolved_message' => $message,
                '_conversation_metadata' => [
                    'clear_pending' => true,
                    'reply_kind' => 'action',
                ],
            ],
        ];
    }

    private function extractAmount(string $message): ?float
    {
        if (! preg_match('/(?:r\$\s*)?(\d+(?:[\.,]\d{1,2})?)/u', $message, $matches)) {
            return null;
        }

        $raw = str_replace('.', '', $matches[1]);
        $amount = (float) str_replace(',', '.', $raw);

        return $amount > 0 ? $amount : null;
    }
}
