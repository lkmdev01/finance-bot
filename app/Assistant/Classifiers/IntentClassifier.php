<?php

namespace App\Assistant\Classifiers;

use App\Assistant\DTO\AssistantContextDTO;
use App\Assistant\DTO\ParsedIntentDTO;
use App\Assistant\Enums\FinancialIntent;
use App\Services\WhatsApp\MessageClassifier;
use App\Services\WhatsApp\SimpleTransactionMessageParser;

class IntentClassifier
{
    public function __construct(
        private readonly MessageClassifier $messageClassifier,
        private readonly SimpleTransactionMessageParser $simpleTransactionMessageParser,
    ) {}

    public function classify(string $message, AssistantContextDTO $context): ParsedIntentDTO
    {
        if ($financialIntent = $this->classifyFinancialIntent($message)) {
            return $financialIntent;
        }

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

    private function classifyFinancialIntent(string $message): ?ParsedIntentDTO
    {
        $normalized = mb_strtolower(trim($message));

        if ($this->looksLikeBalanceQuery($normalized)) {
            return new ParsedIntentDTO(
                intent: FinancialIntent::QUERY_BALANCE,
                confidence: 0.96,
                domain: 'transaction',
                legacyKind: 'query_balance',
                raw: ['kind' => 'query_balance'],
            );
        }

        if ($this->looksLikeMonthReportQuery($normalized)) {
            return new ParsedIntentDTO(
                intent: FinancialIntent::QUERY_MONTH_REPORT,
                confidence: 0.93,
                domain: 'transaction',
                legacyKind: 'query_month_report',
                raw: ['kind' => 'query_month_report'],
            );
        }

        $transactionData = $this->simpleTransactionMessageParser->parse($message);

        if (($transactionData['type'] ?? null) === 'expense') {
            return new ParsedIntentDTO(
                intent: FinancialIntent::CREATE_EXPENSE,
                confidence: 0.95,
                data: $transactionData,
                domain: 'transaction',
                legacyKind: 'transaction_create',
                raw: ['kind' => 'transaction_create', 'payload' => $transactionData],
            );
        }

        if (($transactionData['type'] ?? null) === 'income') {
            return new ParsedIntentDTO(
                intent: FinancialIntent::CREATE_INCOME,
                confidence: 0.95,
                data: $transactionData,
                domain: 'transaction',
                legacyKind: 'transaction_create',
                raw: ['kind' => 'transaction_create', 'payload' => $transactionData],
            );
        }

        return null;
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

    private function looksLikeBalanceQuery(string $message): bool
    {
        foreach ([
            'qual e meu saldo',
            'qual é meu saldo',
            'quanto tenho',
            'quanto sobrou',
            'saldo de hoje',
            'meu saldo',
        ] as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeMonthReportQuery(string $message): bool
    {
        foreach ([
            'quanto gastei esse mes',
            'quanto gastei este mes',
            'resumo do mes',
            'resumo desse mes',
            'relatorio do mes',
            'relatório do mes',
            'resumo mensal',
            'gastos do mes',
            'gastos desse mes',
        ] as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
