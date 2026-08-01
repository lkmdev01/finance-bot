<?php

namespace App\Assistant\Classifiers;

use App\Assistant\DTO\AssistantContextDTO;
use App\Assistant\DTO\ParsedIntentDTO;
use App\Assistant\Enums\FinancialIntent;
use App\Services\WhatsApp\BudgetIntentClassifier;
use App\Services\WhatsApp\BudgetMessageParser;
use App\Services\WhatsApp\DriveIntentClassifier;
use App\Services\WhatsApp\DriveMessageParser;
use App\Services\WhatsApp\IncomingMessageNormalizer;
use App\Services\WhatsApp\InstallmentTransactionMessageParser;
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
use App\Services\WhatsApp\TransactionActionMessageParser;

class IntentClassifier
{
    public function __construct(
        private readonly MessageClassifier $messageClassifier,
        private readonly SimpleTransactionMessageParser $simpleTransactionMessageParser,
        private readonly InstallmentTransactionMessageParser $installmentTransactionMessageParser,
        private readonly TransactionActionMessageParser $transactionActionMessageParser,
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
        private readonly IncomingMessageNormalizer $incomingMessageNormalizer,
    ) {}

    public function classify(string $message, AssistantContextDTO $context): ParsedIntentDTO
    {
        if (($context->state['mode'] ?? null) === 'awaiting_clarification') {
            return new ParsedIntentDTO(
                intent: FinancialIntent::UNKNOWN,
                confidence: 0.35,
                data: [],
                missingFields: [],
                needsConfirmation: false,
                domain: $context->state['last_entities']['topic'] ?? null,
                legacyKind: 'default',
                raw: ['kind' => 'default'],
            );
        }

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
        $raw['payload'] = $this->enrichLegacyPayload($kind, $message, $context, $raw['payload'] ?? []);

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

    private function enrichLegacyPayload(string $kind, string $message, AssistantContextDTO $context, mixed $payload): array
    {
        $existingPayload = is_array($payload) ? $payload : [];

        return match ($kind) {
            'transaction_delete' => $this->transactionActionMessageParser->parseDelete($message, $context->state) ?? $existingPayload,
            'transaction_edit' => $this->transactionActionMessageParser->parseEdit($message, $context->state) ?? $existingPayload,
            'recurring_transaction_create' => $this->recurringTransactionMessageParser->parse($message) ?? $existingPayload,
            'recurring_transaction_edit' => $this->recurringTransactionMessageParser->parseEdit(
                $message,
                $context->state['last_entities']['recurring_description'] ?? null,
            ) ?? $existingPayload,
            'recurring_transaction_delete' => $this->recurringTransactionMessageParser->parseCancel(
                $message,
                $context->state['last_entities']['recurring_description'] ?? null,
            ) ?? $existingPayload,
            default => $existingPayload,
        };
    }

    private function classifyFinancialIntent(string $message): ?ParsedIntentDTO
    {
        $normalized = $this->normalizeAssistantText($message);

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

        $installmentData = $this->installmentTransactionMessageParser->parse($message);

        if ($installmentData !== null) {
            return new ParsedIntentDTO(
                intent: FinancialIntent::CREATE_EXPENSE,
                confidence: 0.95,
                data: $installmentData,
                domain: 'transaction',
                legacyKind: 'create_installment_transaction',
                raw: ['kind' => 'create_installment_transaction', 'payload' => $installmentData],
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
        $normalized = $this->normalizeAssistantText($message);
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

        if ($recurring = $this->classifyStructuredRecurringIntent($message, $normalized, $state)) {
            return $recurring;
        }

        $note = $this->noteIntentClassifier->classify($message, $normalized, $state);
        if ($note !== null) {
            if ($structuredNote = $this->classifyStructuredNoteIntent($message, $normalized, $state, $note)) {
                return $structuredNote;
            }

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
            if ($structuredReminder = $this->classifyStructuredReminderIntent($message, $normalized, $state, $reminder)) {
                return $structuredReminder;
            }

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

        return null;
    }

    private function classifyStructuredNoteIntent(string $message, string $normalized, array $state, array $note): ?ParsedIntentDTO
    {
        if (! in_array($note['kind'], ['note_edit', 'note_delete'], true)) {
            return null;
        }

        $contextNoteId = (int) ($state['last_entities']['note_id'] ?? 0);
        $contextNoteTitle = $state['last_entities']['note_title'] ?? null;
        $usesContextTarget = (($state['last_entities']['topic'] ?? null) === 'notes')
            && ($this->referencesRecentEntity($normalized) || preg_match('/\b(?:nota|notas)\b/u', $normalized) === 1);

        $targetTitle = $this->noteMessageParser->extractActionTarget($message);
        $noteData = array_filter([
            'note_id' => $usesContextTarget && $contextNoteId > 0 ? $contextNoteId : null,
            'current_title' => $targetTitle ?: ($usesContextTarget ? $contextNoteTitle : null),
        ], fn ($value) => $value !== null && $value !== '');

        if ($note['kind'] === 'note_delete') {
            if (($noteData['note_id'] ?? null) === null && empty($noteData['current_title'])) {
                return new ParsedIntentDTO(
                    intent: FinancialIntent::DELETE_NOTE,
                    confidence: $this->confidenceForKind('note_delete_needs_target'),
                    data: [],
                    missingFields: ['target'],
                    domain: 'notes',
                    legacyKind: 'note_delete_needs_target',
                    raw: ['kind' => 'note_delete_needs_target', 'payload' => []],
                );
            }

            return new ParsedIntentDTO(
                intent: FinancialIntent::DELETE_NOTE,
                confidence: $this->confidenceForKind($note['kind']),
                data: $noteData,
                missingFields: [],
                domain: 'notes',
                legacyKind: $note['kind'],
                raw: $note,
            );
        }

        $body = $this->noteMessageParser->extractEditBody($message);
        if (($noteData['note_id'] ?? null) === null && empty($noteData['current_title'])) {
            return new ParsedIntentDTO(
                intent: FinancialIntent::UPDATE_NOTE,
                confidence: $this->confidenceForKind('note_edit_needs_target'),
                data: [],
                missingFields: ['target'],
                domain: 'notes',
                legacyKind: 'note_edit_needs_target',
                raw: ['kind' => 'note_edit_needs_target', 'payload' => []],
            );
        }

        if ($body === null || trim($body) === '') {
            return new ParsedIntentDTO(
                intent: FinancialIntent::UPDATE_NOTE,
                confidence: $this->confidenceForKind('note_edit_needs_content'),
                data: $noteData,
                missingFields: ['content'],
                domain: 'notes',
                legacyKind: 'note_edit_needs_content',
                raw: ['kind' => 'note_edit_needs_content', 'payload' => $noteData],
            );
        }

        return new ParsedIntentDTO(
            intent: FinancialIntent::UPDATE_NOTE,
            confidence: $this->confidenceForKind($note['kind']),
            data: array_merge($noteData, ['body' => $body]),
            missingFields: [],
            domain: 'notes',
            legacyKind: $note['kind'],
            raw: $note,
        );
    }

    private function classifyStructuredReminderIntent(string $message, string $normalized, array $state, array $reminder): ?ParsedIntentDTO
    {
        if (! in_array($reminder['kind'], ['reminder_edit', 'reminder_delete'], true)) {
            return null;
        }

        $contextReminderId = (int) ($state['last_entities']['reminder_id'] ?? 0);
        $contextReminderTitle = $state['last_entities']['reminder_title'] ?? null;
        $usesContextTarget = (($state['last_entities']['topic'] ?? null) === 'reminders')
            && ($this->referencesRecentEntity($normalized) || preg_match('/\b(?:lembrete|lembretes)\b/u', $normalized) === 1);

        $targetTitle = $this->reminderMessageParser->extractActionTarget($message);
        $reminderData = array_filter([
            'reminder_id' => $usesContextTarget && $contextReminderId > 0 ? $contextReminderId : null,
            'current_title' => $targetTitle ?: ($usesContextTarget ? $contextReminderTitle : null),
        ], fn ($value) => $value !== null && $value !== '');

        if ($reminder['kind'] === 'reminder_delete') {
            if (($reminderData['reminder_id'] ?? null) === null && empty($reminderData['current_title'])) {
                return new ParsedIntentDTO(
                    intent: FinancialIntent::DELETE_REMINDER,
                    confidence: $this->confidenceForKind('reminder_delete_needs_target'),
                    data: [],
                    missingFields: ['target'],
                    domain: 'reminders',
                    legacyKind: 'reminder_delete_needs_target',
                    raw: ['kind' => 'reminder_delete_needs_target', 'payload' => []],
                );
            }

            return new ParsedIntentDTO(
                intent: FinancialIntent::DELETE_REMINDER,
                confidence: $this->confidenceForKind($reminder['kind']),
                data: $reminderData,
                missingFields: [],
                domain: 'reminders',
                legacyKind: $reminder['kind'],
                raw: $reminder,
            );
        }

        if (($reminderData['reminder_id'] ?? null) === null && empty($reminderData['current_title'])) {
            return new ParsedIntentDTO(
                intent: FinancialIntent::UPDATE_REMINDER,
                confidence: $this->confidenceForKind('reminder_edit_needs_target'),
                data: [],
                missingFields: ['target'],
                domain: 'reminders',
                legacyKind: 'reminder_edit_needs_target',
                raw: ['kind' => 'reminder_edit_needs_target', 'payload' => []],
            );
        }

        $changes = $this->reminderMessageParser->parseEditFollowUp($message, $reminderData) ?? [];
        $scheduleFields = array_filter([
            'frequency' => $changes['frequency'] ?? null,
            'day_of_week' => $changes['day_of_week'] ?? null,
            'day_of_month' => $changes['day_of_month'] ?? null,
            'month_of_year' => $changes['month_of_year'] ?? null,
            'trigger_time' => $changes['trigger_time'] ?? null,
            'next_trigger_at' => $changes['next_trigger_at'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        if ($scheduleFields === []) {
            return new ParsedIntentDTO(
                intent: FinancialIntent::UPDATE_REMINDER,
                confidence: $this->confidenceForKind('reminder_edit_needs_change'),
                data: $reminderData,
                missingFields: ['change'],
                domain: 'reminders',
                legacyKind: 'reminder_edit_needs_change',
                raw: ['kind' => 'reminder_edit_needs_change', 'payload' => $reminderData],
            );
        }

        return new ParsedIntentDTO(
            intent: FinancialIntent::UPDATE_REMINDER,
            confidence: $this->confidenceForKind($reminder['kind']),
            data: array_merge($reminderData, $scheduleFields),
            missingFields: [],
            domain: 'reminders',
            legacyKind: $reminder['kind'],
            raw: $reminder,
        );
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

        if ($planning['kind'] === 'savings_create') {
            $partial = $this->savingsGoalMessageParser->parsePartialCreate($message) ?? [];
            $missingFields = [];

            if (empty($partial['name'])) {
                $missingFields[] = 'name';
            }

            if (! array_key_exists('target_amount', $partial)) {
                $missingFields[] = 'target_amount';
            }

            if ($missingFields !== []) {
                return new ParsedIntentDTO(
                    intent: FinancialIntent::CREATE_GOAL,
                    confidence: 0.84,
                    data: $partial,
                    missingFields: $missingFields,
                    domain: 'planning',
                    legacyKind: 'savings_needs_details',
                    raw: ['kind' => 'savings_needs_details', 'payload' => $partial],
                );
            }
        }

        if ($planning['kind'] === 'subscription_create') {
            $partial = $this->subscriptionMessageParser->parsePartialCreate($message) ?? [];
            $missingFields = [];

            if (empty($partial['name'])) {
                $missingFields[] = 'name';
            }

            if (! array_key_exists('amount', $partial)) {
                $missingFields[] = 'amount';
            }

            if (empty($partial['billing_cycle'])) {
                $missingFields[] = 'billing_cycle';
            }

            if (! array_key_exists('due_day', $partial)) {
                $missingFields[] = 'due_day';
            }

            if ($missingFields !== []) {
                return new ParsedIntentDTO(
                    intent: FinancialIntent::CREATE_SUBSCRIPTION,
                    confidence: 0.84,
                    data: $partial,
                    missingFields: $missingFields,
                    domain: 'planning',
                    legacyKind: 'subscription_needs_details',
                    raw: ['kind' => 'subscription_needs_details', 'payload' => $partial],
                );
            }
        }

        if ($planning['kind'] === 'savings_edit') {
            $fallbackName = $state['last_entities']['goal_name'] ?? null;
            $payload = $this->savingsGoalMessageParser->parseEdit($message, $fallbackName) ?? [];

            if ($payload === [] && $fallbackName !== null) {
                return new ParsedIntentDTO(
                    intent: FinancialIntent::UPDATE_SAVINGS_GOAL,
                    confidence: 0.82,
                    data: ['name' => $fallbackName],
                    missingFields: ['change'],
                    domain: 'planning',
                    legacyKind: 'savings_edit_needs_change',
                    raw: ['kind' => 'savings_edit_needs_change', 'payload' => ['name' => $fallbackName]],
                );
            }
        }

        if ($planning['kind'] === 'subscription_edit') {
            $fallbackName = $state['last_entities']['subscription_name'] ?? null;
            $payload = $this->subscriptionMessageParser->parseEdit($message, $fallbackName) ?? [];

            if ($payload === [] && $fallbackName === null) {
                return new ParsedIntentDTO(
                    intent: FinancialIntent::UPDATE_SUBSCRIPTION,
                    confidence: 0.82,
                    data: [],
                    missingFields: ['name'],
                    domain: 'planning',
                    legacyKind: 'subscription_edit_needs_target',
                    raw: ['kind' => 'subscription_edit_needs_target', 'payload' => []],
                );
            }

            if ($payload === [] && $fallbackName !== null) {
                return new ParsedIntentDTO(
                    intent: FinancialIntent::UPDATE_SUBSCRIPTION,
                    confidence: 0.82,
                    data: ['name' => $fallbackName],
                    missingFields: ['change'],
                    domain: 'planning',
                    legacyKind: 'subscription_edit_needs_change',
                    raw: ['kind' => 'subscription_edit_needs_change', 'payload' => ['name' => $fallbackName]],
                );
            }
        }

        if ($planning['kind'] === 'subscription_cancel') {
            $fallbackName = $state['last_entities']['subscription_name'] ?? null;
            $payload = $this->subscriptionMessageParser->parseCancel($message, $fallbackName) ?? [];

            if ($payload === []) {
                return new ParsedIntentDTO(
                    intent: FinancialIntent::CANCEL_SUBSCRIPTION,
                    confidence: 0.8,
                    data: [],
                    missingFields: ['name'],
                    domain: 'planning',
                    legacyKind: 'subscription_cancel_needs_target',
                    raw: ['kind' => 'subscription_cancel_needs_target', 'payload' => []],
                );
            }
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

    private function classifyStructuredRecurringIntent(string $message, string $normalized, array $state): ?ParsedIntentDTO
    {
        foreach (['me lembra', 'me lembre', 'lembrete', 'lembrar de', 'lembra de'] as $cue) {
            if (str_contains($normalized, $cue)) {
                return null;
            }
        }

        $partial = $this->recurringTransactionMessageParser->parsePartialCreate($message);
        if (! $this->recurringTransactionMessageParser->looksLikeCreateIntent($normalized) || $partial === null) {
            $fallbackDescription = is_array($state) ? ($state['last_entities']['recurring_description'] ?? null) : null;
            $hasRecurringCue = preg_match('/\b(recorrencia|recorrencias|recorrente|recorrentes|fixo|fixos|fixa|fixas)\b/u', $normalized) === 1;

            $editPayload = $this->recurringTransactionMessageParser->parseEdit($message, $fallbackDescription) ?? [];
            if ($editPayload !== []) {
                return new ParsedIntentDTO(
                    intent: FinancialIntent::UPDATE_RECURRING_TRANSACTION,
                    confidence: 0.88,
                    data: $editPayload,
                    missingFields: [],
                    domain: 'transaction',
                    legacyKind: 'recurring_transaction_edit',
                    raw: ['kind' => 'recurring_transaction_edit', 'payload' => $editPayload],
                );
            }

            if ($fallbackDescription === null && $hasRecurringCue && preg_match('/\b(editar|edita|alterar|altera|ajustar|ajusta|mudar|muda|atualizar|atualiza)\b/u', $normalized) === 1) {
                return new ParsedIntentDTO(
                    intent: FinancialIntent::UPDATE_RECURRING_TRANSACTION,
                    confidence: 0.82,
                    data: [],
                    missingFields: ['description'],
                    domain: 'transaction',
                    legacyKind: 'recurring_transaction_edit_needs_target',
                    raw: ['kind' => 'recurring_transaction_edit_needs_target', 'payload' => []],
                );
            }

            if ($fallbackDescription !== null && $editPayload === [] && preg_match('/\b(editar|edita|alterar|altera|ajustar|ajusta|mudar|muda|atualizar|atualiza)\b/u', $normalized) === 1) {
                return new ParsedIntentDTO(
                    intent: FinancialIntent::UPDATE_RECURRING_TRANSACTION,
                    confidence: 0.82,
                    data: ['description' => $fallbackDescription],
                    missingFields: ['change'],
                    domain: 'transaction',
                    legacyKind: 'recurring_transaction_edit_needs_change',
                    raw: ['kind' => 'recurring_transaction_edit_needs_change', 'payload' => ['description' => $fallbackDescription]],
                );
            }

            $cancelPayload = $this->recurringTransactionMessageParser->parseCancel($message, $fallbackDescription) ?? [];
            if ($fallbackDescription === null && $hasRecurringCue && preg_match('/\b(cancelar|cancela|desativar|desativa|parar|pausar|pausa)\b/u', $normalized) === 1) {
                return new ParsedIntentDTO(
                    intent: FinancialIntent::CANCEL_RECURRING_TRANSACTION,
                    confidence: 0.82,
                    data: [],
                    missingFields: ['description'],
                    domain: 'transaction',
                    legacyKind: 'recurring_transaction_delete_needs_target',
                    raw: ['kind' => 'recurring_transaction_delete_needs_target', 'payload' => []],
                );
            }

            if ($fallbackDescription !== null && $cancelPayload !== []) {
                return new ParsedIntentDTO(
                    intent: FinancialIntent::CANCEL_RECURRING_TRANSACTION,
                    confidence: 0.88,
                    data: $cancelPayload,
                    missingFields: [],
                    domain: 'transaction',
                    legacyKind: 'recurring_transaction_delete',
                    raw: ['kind' => 'recurring_transaction_delete', 'payload' => $cancelPayload],
                );
            }

            if ($this->recurringTransactionMessageParser->looksLikeQueryIntent($normalized)) {
                return new ParsedIntentDTO(
                    intent: FinancialIntent::QUERY_RECURRING_TRANSACTIONS,
                    confidence: 0.9,
                    data: [],
                    missingFields: [],
                    domain: 'transaction',
                    legacyKind: 'recurring_transaction_query',
                    raw: ['kind' => 'recurring_transaction_query'],
                );
            }

            return null;
        }

        if (! array_key_exists('amount', $partial)
            && preg_match('/\b(pagar|gastar|receber|ganhar)\b/u', $normalized) === 1
            && preg_match('/\b(pago|gasto|recebo|ganho|debito|debitar)\b/u', $normalized) !== 1) {
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
        $normalized = $this->normalizeAssistantText($message);
        $lastEntities = $context->state['last_entities'] ?? [];
        $lastAction = $context->lastAction;

        if (($lastEntities['topic'] ?? null) !== 'transactions' || ! in_array($lastAction, ['query_transactions', 'query_category'], true)) {
            return null;
        }

        $deletePayload = $this->transactionActionMessageParser->parseDelete($message, $context->state) ?? [];

        if ($deletePayload !== []) {
            return new ParsedIntentDTO(
                intent: FinancialIntent::DELETE_TRANSACTION,
                confidence: 0.9,
                data: $deletePayload,
                domain: 'transaction',
                legacyKind: 'transaction_delete',
                raw: ['kind' => 'transaction_delete'],
            );
        }

        $editPayload = $this->transactionActionMessageParser->parseEdit($message, $context->state) ?? [];

        if ($editPayload !== []) {
            return new ParsedIntentDTO(
                intent: FinancialIntent::UPDATE_TRANSACTION,
                confidence: 0.88,
                data: $editPayload,
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
            'create_savings_goal', 'savings_create', 'savings_needs_details' => FinancialIntent::CREATE_GOAL,
            'query_savings', 'savings_query' => FinancialIntent::QUERY_SAVINGS,
            'update_savings_goal', 'savings_edit', 'savings_edit_needs_change' => FinancialIntent::UPDATE_SAVINGS_GOAL,
            'create_subscription', 'subscription_create', 'subscription_needs_details' => FinancialIntent::CREATE_SUBSCRIPTION,
            'query_subscriptions', 'subscription_query' => FinancialIntent::QUERY_SUBSCRIPTIONS,
            'update_subscription', 'subscription_edit', 'subscription_edit_needs_change', 'subscription_edit_needs_target' => FinancialIntent::UPDATE_SUBSCRIPTION,
            'cancel_subscription', 'subscription_cancel', 'subscription_cancel_needs_target' => FinancialIntent::CANCEL_SUBSCRIPTION,
            'create_recurring_transaction', 'recurring_transaction_create', 'recurring_transaction_needs_amount' => FinancialIntent::CREATE_RECURRING_TRANSACTION,
            'query_recurring_transactions', 'recurring_transaction_query' => FinancialIntent::QUERY_RECURRING_TRANSACTIONS,
            'update_recurring_transaction', 'recurring_transaction_edit', 'recurring_transaction_edit_needs_change', 'recurring_transaction_edit_needs_target' => FinancialIntent::UPDATE_RECURRING_TRANSACTION,
            'cancel_recurring_transaction', 'recurring_transaction_delete', 'recurring_transaction_delete_needs_target' => FinancialIntent::CANCEL_RECURRING_TRANSACTION,
            'create_note', 'note_create', 'note_needs_content' => FinancialIntent::CREATE_NOTE,
            'query_notes', 'note_query' => FinancialIntent::QUERY_NOTES,
            'edit_note', 'note_edit', 'note_edit_needs_target', 'note_edit_needs_content' => FinancialIntent::UPDATE_NOTE,
            'delete_note', 'note_delete', 'note_delete_needs_target' => FinancialIntent::DELETE_NOTE,
            'create_reminder', 'reminder_create', 'reminder_needs_schedule' => FinancialIntent::CREATE_REMINDER,
            'query_reminders', 'reminder_query' => FinancialIntent::QUERY_REMINDERS,
            'edit_reminder', 'reminder_edit', 'reminder_edit_needs_target', 'reminder_edit_needs_change' => FinancialIntent::UPDATE_REMINDER,
            'delete_reminder', 'reminder_delete', 'reminder_delete_needs_target' => FinancialIntent::DELETE_REMINDER,
            'attach_receipt' => FinancialIntent::ATTACH_RECEIPT,
            'drive_save', 'drive_needs_file', 'create_drive_file' => FinancialIntent::CREATE_DRIVE_FILE,
            'query_drive_files', 'drive_query' => FinancialIntent::QUERY_DRIVE_FILES,
            'help', 'greeting', 'dashboard_link' => FinancialIntent::HELP,
            default => FinancialIntent::UNKNOWN,
        };
    }

    private function confidenceForKind(string $kind): float
    {
        return match ($kind) {
            'default', 'acknowledgement' => 0.35,
            'confirmation', 'cancellation' => 0.72,
            'greeting', 'help', 'dashboard_link' => 0.95,
            'budget_needs_details', 'savings_needs_details', 'subscription_needs_details', 'savings_edit_needs_change', 'subscription_edit_needs_change', 'subscription_edit_needs_target', 'subscription_cancel_needs_target', 'recurring_transaction_edit_needs_change', 'recurring_transaction_edit_needs_target', 'recurring_transaction_delete_needs_target', 'note_edit_needs_target', 'note_edit_needs_content', 'note_delete_needs_target', 'reminder_edit_needs_target', 'reminder_edit_needs_change', 'reminder_delete_needs_target' => 0.83,
            default => 0.9,
        };
    }

    private function missingFieldsForKind(string $kind): array
    {
        return match ($kind) {
            'drive_needs_file' => ['file'],
            'note_needs_content' => ['content'],
            'note_edit_needs_target', 'note_delete_needs_target', 'reminder_edit_needs_target', 'reminder_delete_needs_target' => ['target'],
            'note_edit_needs_content' => ['content'],
            'reminder_needs_schedule' => ['schedule'],
            'reminder_edit_needs_change' => ['change'],
            'recurring_transaction_needs_amount' => ['amount'],
            'budget_needs_details' => ['amount', 'category_name'],
            'savings_needs_details' => ['name', 'target_amount'],
            'savings_edit_needs_change' => ['change'],
            'subscription_needs_details' => ['name', 'amount', 'billing_cycle', 'due_day'],
            'subscription_edit_needs_target' => ['name'],
            'subscription_edit_needs_change' => ['change'],
            'subscription_cancel_needs_target' => ['name'],
            'recurring_transaction_edit_needs_target' => ['description'],
            'recurring_transaction_edit_needs_change' => ['change'],
            'recurring_transaction_delete_needs_target' => ['description'],
            default => [],
        };
    }

    private function looksLikeBalanceQuery(string $message): bool
    {
        foreach ([
            'qual e meu saldo',
            'qual Ã© meu saldo',
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
        if (
            preg_match('/\b(?:gasto|gastos|despesa|despesas|relatorio|relatório|resumo)\b/u', $message) === 1
            && preg_match('/\b(?:sem categoria|sem categorias|com categoria|com categorias|categorizado|categorizados)\b/u', $message) === 1
        ) {
            return true;
        }

        if (
            preg_match('/\b(?:gasto|gastos|despesa|despesas|relatorio|relatório|resumo)\b/u', $message) === 1
            && preg_match('/\b(?:de|do|da|entre|dia)\b/u', $message) === 1
            && preg_match('/\b(?:a|ate|até|e)\b/u', $message) === 1
            && preg_match('/\d{1,2}/u', $message) === 1
        ) {
            return true;
        }

        foreach ([
            'quanto gastei esse mes',
            'quanto gastei este mes',
            'resumo do mes',
            'resumo desse mes',
            'relatorio do mes',
            'relatÃ³rio do mes',
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

    private function looksLikeReminderStyleRecurringWithoutAmount(string $message): bool
    {
        $hasInfinitiveOnly = preg_match('/\b(pagar|gastar|receber|ganhar)\b/u', $message) === 1;
        $hasAccountingVerb = preg_match('/\b(pago|gasto|recebo|ganho|debito|debitar)\b/u', $message) === 1;

        return $hasInfinitiveOnly && ! $hasAccountingVerb;
    }

    private function referencesRecentEntity(string $message): bool
    {
        return preg_match('/\b(?:essa|esse|esta|este|ela|ele|ultima|ultimo)\b/u', $message) === 1;
    }

    private function normalizeAssistantText(string $message): string
    {
        return $this->incomingMessageNormalizer->normalize($message);
    }
}
