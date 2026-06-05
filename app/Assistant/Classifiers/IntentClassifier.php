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

        if ($contextualIntent = $this->classifyContextualFinancialIntent($message, $context)) {
            return $contextualIntent;
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

    private function classifyContextualFinancialIntent(string $message, AssistantContextDTO $context): ?ParsedIntentDTO
    {
        $normalized = mb_strtolower(trim($message));
        $lastEntities = $context->state['last_entities'] ?? [];
        $lastAction = $context->lastAction;

        if (($lastEntities['topic'] ?? null) !== 'transactions' || ! in_array($lastAction, ['query_transactions', 'query_category'], true)) {
            return null;
        }

        if (preg_match('/\b(apaga|apagar|remove|remover|deleta|deletar|exclui|excluir)\b/u', $normalized) === 1) {
            return new ParsedIntentDTO(
                intent: FinancialIntent::DELETE_TRANSACTION,
                confidence: 0.9,
                data: [
                    'transaction_id' => $lastEntities['latest_transaction_id'] ?? null,
                    'reference' => 'last_transaction',
                ],
                domain: 'transaction',
                legacyKind: 'transaction_delete',
                raw: ['kind' => 'transaction_delete'],
            );
        }

        if (preg_match('/\b(na verdade|corrige|corrigir|ajusta|ajustar|muda|mudar|foi no cartao|foi no cartão)\b/u', $normalized) === 1
            || preg_match('/\bfoi no cartao\b/u', $normalized) === 1
            || preg_match('/\bfoi no cartão\b/u', $normalized) === 1) {
            $payload = [
                'transaction_id' => $lastEntities['latest_transaction_id'] ?? null,
                'reference' => 'last_transaction',
            ];

            if (preg_match('/(?:r\\$\\s*)?(\\d+(?:[\\.,]\\d{1,2})?)/u', $message, $amountMatches) === 1) {
                $payload['amount'] = (float) str_replace(',', '.', str_replace('.', '', $amountMatches[1]));
            }

            if (str_contains($normalized, 'cartao') || str_contains($normalized, 'cartão')) {
                $payload['payment_method'] = 'credit';
            }

            return new ParsedIntentDTO(
                intent: FinancialIntent::UPDATE_TRANSACTION,
                confidence: 0.88,
                data: $payload,
                domain: 'transaction',
                legacyKind: 'transaction_edit',
                raw: ['kind' => 'transaction_edit'],
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
            'query_report', 'query_month_report', 'query_budgets' => FinancialIntent::QUERY_MONTH_REPORT,
            'query_transactions' => FinancialIntent::LIST_TRANSACTIONS,
            'edit_transaction', 'transaction_edit' => FinancialIntent::UPDATE_TRANSACTION,
            'delete_transaction', 'transaction_delete', 'undo' => FinancialIntent::DELETE_TRANSACTION,
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
