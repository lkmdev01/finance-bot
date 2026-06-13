<?php

namespace App\Services\WhatsApp\Resolvers;

use App\Services\WhatsApp\BudgetMessageParser;
use App\Services\WhatsApp\ConversationContextResolver;
use App\Services\WhatsApp\CreditCardMessageParser;
use App\Services\WhatsApp\InstallmentTransactionMessageParser;
use App\Services\WhatsApp\RecurringTransactionMessageParser;
use App\Services\WhatsApp\ReminderMessageParser;
use App\Services\WhatsApp\NoteMessageParser;
use App\Services\WhatsApp\DriveMessageParser;
use App\Services\WhatsApp\SavingsGoalMessageParser;
use App\Services\WhatsApp\SubscriptionMessageParser;
use App\Services\WhatsApp\SimpleTransactionMessageParser;
use App\Services\WhatsApp\TransactionActionMessageParser;
use App\Services\WhatsApp\TransactionSplitMessageParser;

class DomainRoutingResolver
{
    public function __construct(
        private readonly ConversationContextResolver $contextResolver,
        private readonly BudgetMessageParser $budgetMessageParser,
        private readonly ReminderMessageParser $reminderMessageParser,
        private readonly NoteMessageParser $noteMessageParser,
        private readonly DriveMessageParser $driveMessageParser,
        private readonly SavingsGoalMessageParser $savingsGoalMessageParser,
        private readonly SubscriptionMessageParser $subscriptionMessageParser,
        private readonly CreditCardMessageParser $creditCardMessageParser,
        private readonly SimpleTransactionMessageParser $simpleTransactionMessageParser,
        private readonly TransactionActionMessageParser $transactionActionMessageParser,
        private readonly RecurringTransactionMessageParser $recurringTransactionMessageParser,
        private readonly InstallmentTransactionMessageParser $installmentTransactionMessageParser,
        private readonly TransactionSplitMessageParser $transactionSplitMessageParser,
    ) {}

    public function resolve(string $classification, string $message, array $state): array
    {
        return match ($classification) {
            'undo' => [
                'handled' => false,
                'result' => [
                    'reply' => '',
                    'action' => 'undo_last_action',
                    '_resolved_message' => $message,
                    '_conversation_metadata' => [
                        'clear_pending' => true,
                        'reply_kind' => 'action',
                    ],
                ],
            ],
            'budget_query' => $this->queryResult('query_budgets', $message),
            'drive_query' => $this->queryResult('query_drive_files', $message),
            'budget_create' => $this->actionResult(
                'create_budget',
                'budget_data',
                $this->budgetMessageParser->parseCreate($message) ?? [],
                $message
            ),
            'budget_edit' => $this->actionResult(
                'update_budget',
                'budget_data',
                $this->budgetMessageParser->parseEdit(
                    $message,
                    $this->contextResolver->recentEntityName($state, 'budget', 'category_name'),
                    $this->contextResolver->recentBudgetPeriod($state)
                ) ?? [],
                $message
            ),
            'budget_delete' => $this->actionResult(
                'delete_budget',
                'budget_data',
                $this->budgetMessageParser->parseDelete(
                    $message,
                    $this->contextResolver->recentEntityName($state, 'budget', 'category_name'),
                    $this->contextResolver->recentBudgetPeriod($state)
                ) ?? [],
                $message
            ),
            'savings_query' => $this->queryResult('query_savings', $message),
            'note_query' => $this->queryResult('query_notes', $message),
            'reminder_query' => $this->queryResult('query_reminders', $message),
            'subscription_query' => $this->queryResult('query_subscriptions', $message),
            'recurring_transaction_query' => $this->queryResult('query_recurring_transactions', $message),
            'credit_card_query' => $this->queryResult('query_credit_cards', $message),
            'projection_query' => $this->queryResult('query_projections', $message),
            'reminder_create' => $this->actionResult(
                'create_reminder',
                'reminder_data',
                $this->reminderMessageParser->parse($message) ?? [],
                $message
            ),
            'reminder_edit' => $this->actionResult(
                'edit_reminder',
                'reminder_data',
                [],
                $message
            ),
            'reminder_delete' => $this->actionResult(
                'delete_reminder',
                'reminder_data',
                [],
                $message
            ),
            'note_create' => $this->actionResult(
                'create_note',
                'note_data',
                $this->noteMessageParser->parseCreate($message) ?? [],
                $message
            ),
            'drive_save' => $this->actionResult(
                'create_drive_file',
                'drive_data',
                $this->driveMessageParser->parseSave($message, $state) ?? [],
                $message
            ),
            'note_delete' => $this->actionResult(
                'delete_note',
                'note_data',
                [],
                $message
            ),
            'note_edit' => $this->actionResult(
                'edit_note',
                'note_data',
                [],
                $message
            ),
            'savings_create' => $this->actionResult(
                'create_savings_goal',
                'goal_data',
                $this->savingsGoalMessageParser->parse($message) ?? [],
                $message
            ),
            'savings_edit' => $this->actionResult(
                'update_savings_goal',
                'goal_data',
                $this->savingsGoalMessageParser->parseEdit(
                    $message,
                    $this->contextResolver->recentEntityName($state, 'savings', 'goal_name')
                ) ?? [],
                $message
            ),
            'subscription_create' => $this->actionResult(
                'create_subscription',
                'subscription_data',
                $this->subscriptionMessageParser->parse($message) ?? [],
                $message
            ),
            'credit_card_create' => $this->actionResult(
                'create_credit_card',
                'credit_card_data',
                $this->creditCardMessageParser->parseCreate($message) ?? [],
                $message
            ),
            'subscription_edit' => $this->actionResult(
                'update_subscription',
                'subscription_data',
                $this->subscriptionMessageParser->parseEdit(
                    $message,
                    $this->contextResolver->recentEntityName($state, 'subscriptions', 'subscription_name')
                ) ?? [],
                $message
            ),
            'subscription_cancel' => $this->actionResult(
                'cancel_subscription',
                'subscription_data',
                $this->subscriptionMessageParser->parseCancel(
                    $message,
                    $this->contextResolver->recentEntityName($state, 'subscriptions', 'subscription_name')
                ) ?? [],
                $message
            ),
            'recurring_transaction_create' => $this->actionResult(
                'create_recurring_transaction',
                'recurring_data',
                $this->recurringTransactionMessageParser->parse($message) ?? [],
                $message
            ),
            'recurring_transaction_edit' => $this->actionResult(
                'update_recurring_transaction',
                'recurring_data',
                $this->recurringTransactionMessageParser->parseEdit(
                    $message,
                    $this->contextResolver->recentEntityName($state, 'recurring_transactions', 'recurring_description')
                ) ?? [],
                $message
            ),
            'recurring_transaction_delete' => $this->actionResult(
                'cancel_recurring_transaction',
                'recurring_data',
                $this->recurringTransactionMessageParser->parseCancel(
                    $message,
                    $this->contextResolver->recentEntityName($state, 'recurring_transactions', 'recurring_description')
                ) ?? [],
                $message
            ),
            'installment_transaction_create' => $this->actionResult(
                'create_installment_transaction',
                'installment_data',
                $this->installmentTransactionMessageParser->parse($message) ?? [],
                $message
            ),
            'compound_transaction_create' => $this->actionResult(
                'create_transaction',
                'transaction_data',
                [],
                $message
            ),
            'transaction_create' => $this->actionResult(
                'create_transaction',
                'transaction_data',
                $this->simpleTransactionMessageParser->parse($message) ?? [],
                $message
            ),
            'transaction_split' => $this->buildTransactionSplitResult($message, $state),
            'transaction_edit' => $this->actionResult(
                'edit_transaction',
                'transaction_data',
                $this->transactionActionMessageParser->parseEdit($message, $state) ?? [],
                $message
            ),
            'transaction_delete' => $this->actionResult(
                'delete_transaction',
                'transaction_data',
                $this->transactionActionMessageParser->parseDelete($message, $state) ?? [],
                $message
            ),
            'transaction_follow_up' => $this->queryResult($state['last_action'] ?? 'query_transactions', $message),
            default => ['handled' => false],
        };
    }

    private function buildTransactionSplitResult(string $message, array $state): array
    {
        $payload = $this->transactionSplitMessageParser->parse($message) ?? [];

        if (empty($payload['reference']) && ! empty($state['last_entities']['transaction_id'])) {
            $payload['reference'] = 'recent';
        }

        return $this->actionResult('split_transaction', 'transaction_data', $payload, $message);
    }

    private function queryResult(string $action, string $message): array
    {
        return [
            'handled' => false,
            'result' => [
                'reply' => '',
                'action' => $action,
                'transaction_data' => null,
                '_resolved_message' => $message,
                '_conversation_metadata' => [
                    'clear_pending' => true,
                    'reply_kind' => 'query',
                ],
            ],
        ];
    }

    private function actionResult(string $action, string $payloadKey, array $payload, string $message): array
    {
        return [
            'handled' => false,
            'result' => [
                'reply' => '',
                'action' => $action,
                $payloadKey => $payload,
                '_resolved_message' => $message,
                '_conversation_metadata' => [
                    'clear_pending' => true,
                    'reply_kind' => 'action',
                ],
            ],
        ];
    }
}
