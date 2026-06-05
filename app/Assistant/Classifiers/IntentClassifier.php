<?php

namespace App\Assistant\Classifiers;

use App\Assistant\DTO\AssistantContextDTO;
use App\Assistant\DTO\ParsedIntentDTO;
use App\Assistant\Enums\FinancialIntent;
use App\Services\WhatsApp\MessageClassifier;

class IntentClassifier
{
    public function __construct(
        private readonly MessageClassifier $messageClassifier,
    ) {}

    public function classify(string $message, AssistantContextDTO $context): ParsedIntentDTO
    {
        $raw = $this->messageClassifier->classify($message, $context->state);
        $kind = $raw['kind'] ?? 'default';

        return new ParsedIntentDTO(
            intent: $this->mapIntent($kind),
            confidence: $this->confidenceForKind($kind),
            data: $raw['payload'] ?? [],
            missingFields: $this->missingFieldsForKind($kind),
            needsConfirmation: in_array($kind, ['confirmation', 'confirm_large_transaction'], true),
            domain: $raw['domain'] ?? null,
            legacyKind: $kind,
            raw: $raw,
        );
    }

    private function mapIntent(string $kind): FinancialIntent
    {
        return match ($kind) {
            'create_expense', 'create_transaction', 'split_transaction', 'create_installment_transaction' => FinancialIntent::CREATE_EXPENSE,
            'create_income' => FinancialIntent::CREATE_INCOME,
            'query_balance' => FinancialIntent::QUERY_BALANCE,
            'query_category', 'query_category_spending' => FinancialIntent::QUERY_CATEGORY_SPENDING,
            'query_report', 'query_transactions', 'query_month_report', 'query_budgets' => FinancialIntent::QUERY_MONTH_REPORT,
            'edit_transaction' => FinancialIntent::UPDATE_TRANSACTION,
            'delete_transaction', 'undo' => FinancialIntent::DELETE_TRANSACTION,
            'create_budget' => FinancialIntent::CREATE_BUDGET,
            'create_savings_goal' => FinancialIntent::CREATE_GOAL,
            'attach_receipt', 'drive_needs_file', 'create_drive_file', 'query_drive_files' => FinancialIntent::ATTACH_RECEIPT,
            'help', 'greeting' => FinancialIntent::HELP,
            default => FinancialIntent::UNKNOWN,
        };
    }

    private function confidenceForKind(string $kind): float
    {
        return match ($kind) {
            'default', 'acknowledgement' => 0.35,
            'confirmation', 'cancellation' => 0.72,
            'greeting', 'help' => 0.95,
            default => 0.9,
        };
    }

    private function missingFieldsForKind(string $kind): array
    {
        return match ($kind) {
            'drive_needs_file' => ['file'],
            'note_needs_content' => ['content'],
            'reminder_needs_schedule' => ['schedule'],
            'recurring_transaction_needs_amount' => ['amount'],
            default => [],
        };
    }
}
