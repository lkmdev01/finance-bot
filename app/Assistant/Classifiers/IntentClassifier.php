<?php

namespace App\Assistant\Classifiers;

use App\Assistant\DTO\AssistantContextDTO;
use App\Assistant\DTO\ParsedIntentDTO;
use App\Assistant\Enums\FinancialIntent;
use App\Services\WhatsApp\BudgetIntentClassifier;
use App\Services\WhatsApp\BudgetMessageParser;
use App\Services\WhatsApp\DriveIntentClassifier;
use App\Services\WhatsApp\DriveMessageParser;
use App\Services\WhatsApp\MessageClassifier;
use App\Services\WhatsApp\NoteIntentClassifier;
use App\Services\WhatsApp\NoteMessageParser;
use App\Services\WhatsApp\PlanningIntentClassifier;
use App\Services\WhatsApp\RecurringTransactionMessageParser;
use App\Services\WhatsApp\ReminderIntentClassifier;
use App\Services\WhatsApp\ReminderMessageParser;
use App\Services\WhatsApp\SavingsGoalMessageParser;
use App\Services\WhatsApp\SimpleTransactionMessageParser;
use App\Services\WhatsApp\SubscriptionMessageParser;

class IntentClassifier
{
    public function __construct(
        private readonly MessageClassifier $messageClassifier,
        private readonly SimpleTransactionMessageParser $simpleTransactionMessageParser,
        private readonly BudgetIntentClassifier $budgetIntentClassifier,
        private readonly BudgetMessageParser $budgetMessageParser,
        private readonly NoteIntentClassifier $noteIntentClassifier,
        private readonly NoteMessageParser $noteMessageParser,
        private readonly PlanningIntentClassifier $planningIntentClassifier,
        private readonly SavingsGoalMessageParser $savingsGoalMessageParser,
        private readonly SubscriptionMessageParser $subscriptionMessageParser,
        private readonly RecurringTransactionMessageParser $recurringTransactionMessageParser,
        private readonly ReminderIntentClassifier $reminderIntentClassifier,
        private readonly ReminderMessageParser $reminderMessageParser,
        private readonly DriveIntentClassifier $driveIntentClassifier,
        private readonly DriveMessageParser $driveMessageParser,
    ) {}

    public function classify(string $message, AssistantContextDTO $context): ParsedIntentDTO
    {
        if ($financialIntent = $this->classifyFinancialIntent($message)) {
            return $financialIntent;
        }

        if ($contextualIntent = $this->classifyContextualFinancialIntent($message, $context)) {
            return $contextualIntent;
        }

        if ($domainIntent = $this->classifyStructuredDomainIntent($message, $context)) {
            return $domainIntent;
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

    private function classifyStructuredDomainIntent(string $message, AssistantContextDTO $context): ?ParsedIntentDTO
    {
        $normalized = mb_strtolower(trim($message));
        $state = $context->state;

        $budget = $this->budgetIntentClassifier->classify($message, $normalized, $state);
        if ($budget !== null) {
            $budgetPayload = match ($budget['kind']) {
                'budget_create' => $this->budgetMessageParser->parseCreate($message) ?? ($budget['payload'] ?? []),
                default => $budget['payload'] ?? [],
            };

            return new ParsedIntentDTO(
                intent: $this->mapIntent($budget['kind']),
                confidence: 0.92,
                data: $budgetPayload,
                missingFields: $this->missingFieldsForKind($budget['kind']),
                domain: 'budget',
                legacyKind: $budget['kind'],
                raw: $budget,
            );
        }

        if ($budgetPartial = $this->classifyBudgetCreateWithMissingFields($message)) {
            return $budgetPartial;
        }

        if ($planning = $this->classifyStructuredPlanningIntent($message, $normalized, $state)) {
            return $planning;
        }

        if ($recurring = $this->classifyStructuredRecurringIntent($message, $normalized)) {
            return $recurring;
        }

        $drive = $this->driveIntentClassifier->classify($message, $normalized, $state);
        if ($drive !== null) {
            $drivePayload = match ($drive['kind']) {
                'drive_save' => $this->driveMessageParser->parseSave($message, $state) ?? ($drive['payload'] ?? []),
                'drive_query' => $this->driveMessageParser->parseQuery($message, $state),
                default => $drive['payload'] ?? [],
            };

            return new ParsedIntentDTO(
                intent: $this->mapIntent($drive['kind']),
                confidence: 0.91,
                data: $drivePayload,
                missingFields: $this->missingFieldsForKind($drive['kind']),
                domain: 'drive',
                legacyKind: $drive['kind'],
                raw: $drive,
            );
        }

        $note = $this->noteIntentClassifier->classify($message, $normalized, $state);
        if ($note !== null) {
            $notePayload = match ($note['kind']) {
                'note_create' => $this->noteMessageParser->parseCreate($message) ?? ($note['payload'] ?? []),
                'note_query' => ['term' => $this->noteMessageParser->extractQueryTerm($message)],
                default => $note['payload'] ?? [],
            };

            return new ParsedIntentDTO(
                intent: $this->mapIntent($note['kind']),
                confidence: 0.92,
                data: $notePayload,
                missingFields: $this->missingFieldsForKind($note['kind']),
                domain: 'notes',
                legacyKind: $note['kind'],
                raw: $note,
            );
        }

        $reminder = $this->reminderIntentClassifier->classify($message, $normalized, $state);
        if ($reminder !== null) {
            $reminderPayload = match ($reminder['kind']) {
                'reminder_create' => $this->reminderMessageParser->parse($message) ?? ($reminder['payload'] ?? []),
                'reminder_query' => [],
                default => $reminder['payload'] ?? [],
            };

            return new ParsedIntentDTO(
                intent: $this->mapIntent($reminder['kind']),
                confidence: 0.92,
                data: $reminderPayload,
                missingFields: $this->missingFieldsForKind($reminder['kind']),
                domain: 'reminders',
                legacyKind: $reminder['kind'],
                raw: $reminder,
            );
        }

        return null;
    }

    private function classifyBudgetCreateWithMissingFields(string $message): ?ParsedIntentDTO
    {
        $partial = $this->budgetMessageParser->parsePartialCreate($message);
        if ($partial === null) {
            return null;
        }

        $missingFields = [];

        if (! array_key_exists('amount', $partial)) {
            $missingFields[] = 'amount';
        }

        if (empty($partial['category_name'])) {
            $missingFields[] = 'category_name';
        }

        if ($missingFields === []) {
            return null;
        }

        return new ParsedIntentDTO(
            intent: FinancialIntent::CREATE_BUDGET,
            confidence: 0.83,
            data: $partial,
            missingFields: $missingFields,
            domain: 'budget',
            legacyKind: 'budget_needs_details',
            raw: ['kind' => 'budget_needs_details', 'payload' => $partial],
        );
    }

    private function classifyStructuredPlanningIntent(string $message, string $normalized, array $state): ?ParsedIntentDTO
    {
        $planning = $this->planningIntentClassifier->classify($message, $normalized, $state);
        if ($planning === null) {
            return null;
        }

        $payload = match ($planning['kind']) {
            'savings_create' => $this->savingsGoalMessageParser->parse($message) ?? [],
            'savings_edit' => $this->savingsGoalMessageParser->parseEdit($message, $state['last_entities']['goal_name'] ?? null) ?? [],
            'subscription_create' => $this->subscriptionMessageParser->parse($message) ?? [],
            'subscription_edit' => $this->subscriptionMessageParser->parseEdit($message, $state['last_entities']['subscription_name'] ?? null) ?? [],
            'subscription_cancel' => $this->subscriptionMessageParser->parseCancel($message, $state['last_entities']['subscription_name'] ?? null) ?? [],
            default => $planning['payload'] ?? [],
        };

        return new ParsedIntentDTO(
            intent: $this->mapIntent($planning['kind']),
            confidence: 0.9,
            data: $payload,
            missingFields: $this->missingFieldsForKind($planning['kind']),
            domain: 'planning',
            legacyKind: $planning['kind'],
            raw: $planning,
        );
    }

    private function classifyStructuredRecurringIntent(string $message, string $normalized): ?ParsedIntentDTO
    {
        foreach (['me lembra', 'me lembre', 'lembrete', 'lembrar de', 'lembra de'] as $cue) {
            if (str_contains($normalized, $cue)) {
                return null;
            }
        }

        $partial = $this->recurringTransactionMessageParser->parsePartialCreate($message);
        if (! $this->recurringTransactionMessageParser->looksLikeCreateIntent($normalized) || $partial === null) {
            return null;
        }

        if (! array_key_exists('amount', $partial)) {
            return new ParsedIntentDTO(
                intent: FinancialIntent::CREATE_RECURRING_TRANSACTION,
                confidence: 0.88,
                data: $partial,
                missingFields: ['amount'],
                domain: 'transaction',
                legacyKind: 'recurring_transaction_needs_amount',
                raw: ['kind' => 'recurring_transaction_needs_amount', 'payload' => $partial],
            );
        }

        return new ParsedIntentDTO(
            intent: FinancialIntent::CREATE_RECURRING_TRANSACTION,
            confidence: 0.91,
            data: $this->recurringTransactionMessageParser->parse($message) ?? $partial,
            missingFields: [],
            domain: 'transaction',
            legacyKind: 'recurring_transaction_create',
            raw: ['kind' => 'recurring_transaction_create', 'payload' => $partial],
        );
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
            'query_report', 'query_month_report' => FinancialIntent::QUERY_MONTH_REPORT,
            'query_transactions' => FinancialIntent::LIST_TRANSACTIONS,
            'edit_transaction', 'transaction_edit' => FinancialIntent::UPDATE_TRANSACTION,
            'delete_transaction', 'transaction_delete', 'undo' => FinancialIntent::DELETE_TRANSACTION,
            'create_budget', 'budget_create', 'budget_needs_details' => FinancialIntent::CREATE_BUDGET,
            'query_budgets', 'budget_query' => FinancialIntent::QUERY_BUDGETS,
            'create_savings_goal', 'savings_create' => FinancialIntent::CREATE_GOAL,
            'query_savings', 'savings_query' => FinancialIntent::QUERY_SAVINGS,
            'update_savings_goal', 'savings_edit' => FinancialIntent::UPDATE_SAVINGS_GOAL,
            'create_subscription', 'subscription_create' => FinancialIntent::CREATE_SUBSCRIPTION,
            'query_subscriptions', 'subscription_query' => FinancialIntent::QUERY_SUBSCRIPTIONS,
            'update_subscription', 'subscription_edit' => FinancialIntent::UPDATE_SUBSCRIPTION,
            'cancel_subscription', 'subscription_cancel' => FinancialIntent::CANCEL_SUBSCRIPTION,
            'create_recurring_transaction', 'recurring_transaction_create', 'recurring_transaction_needs_amount' => FinancialIntent::CREATE_RECURRING_TRANSACTION,
            'update_recurring_transaction', 'recurring_transaction_edit' => FinancialIntent::UPDATE_RECURRING_TRANSACTION,
            'cancel_recurring_transaction', 'recurring_transaction_delete' => FinancialIntent::CANCEL_RECURRING_TRANSACTION,
            'create_note', 'note_create', 'note_needs_content' => FinancialIntent::CREATE_NOTE,
            'query_notes', 'note_query' => FinancialIntent::QUERY_NOTES,
            'create_reminder', 'reminder_create', 'reminder_needs_schedule' => FinancialIntent::CREATE_REMINDER,
            'query_reminders', 'reminder_query' => FinancialIntent::QUERY_REMINDERS,
            'attach_receipt' => FinancialIntent::ATTACH_RECEIPT,
            'drive_save', 'drive_needs_file', 'create_drive_file' => FinancialIntent::CREATE_DRIVE_FILE,
            'query_drive_files', 'drive_query' => FinancialIntent::QUERY_DRIVE_FILES,
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
            'budget_needs_details' => 0.83,
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
            'budget_needs_details' => ['amount', 'category_name'],
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
