<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use App\Models\WhatsAppContact;

class ConversationOrchestrator
{
    public function __construct(
        private readonly MessageClassifier $classifier,
        private readonly ResponseComposer $composer,
        private readonly ConversationStateService $stateService,
        private readonly BudgetMessageParser $budgetMessageParser,
        private readonly SavingsGoalMessageParser $savingsGoalMessageParser,
        private readonly SubscriptionMessageParser $subscriptionMessageParser,
        private readonly TransactionActionMessageParser $transactionActionMessageParser,
    ) {}

    public function beforeAI(string $message, User $user, WhatsAppContact $contact): array
    {
        $state = $this->stateService->getState($contact);
        $classification = $this->classifier->classify($message, $state);

        if (($state['mode'] ?? 'idle') === 'awaiting_clarification'
            && in_array($classification['kind'], ['default', 'budget_query'], true)
        ) {
            $clarification = $this->handleClarification($message, $state);
            if ($clarification !== null) {
                return $clarification;
            }
        }

        return match ($classification['kind']) {
            'greeting' => [
                'handled' => true,
                'reply' => $this->composer->composeGreeting($user),
                'action' => null,
                'metadata' => ['clear_pending' => true, 'reply_kind' => 'message'],
            ],
            'acknowledgement' => [
                'handled' => true,
                'reply' => $this->composer->composeNeutralAcknowledgement($state['last_action'] ?? null, $state['last_entities'] ?? []),
                'action' => null,
                'metadata' => ['clear_pending' => false, 'reply_kind' => 'message'],
            ],
            'cancellation' => $this->handleCancellation($state),
            'confirmation' => $this->handleConfirmation($state),
            'budget_query' => $this->buildQueryResult('query_budgets', $message),
            'budget_edit' => $this->buildBudgetEditResult($message, $state),
            'budget_delete' => $this->buildBudgetDeleteResult($message, $state),
            'savings_query' => $this->buildQueryResult('query_savings', $message),
            'subscription_query' => $this->buildQueryResult('query_subscriptions', $message),
            'projection_query' => $this->buildQueryResult('query_projections', $message),
            'savings_create' => $this->buildSavingsCreateResult($message),
            'savings_edit' => $this->buildSavingsEditResult($message, $state),
            'subscription_create' => $this->buildSubscriptionCreateResult($message),
            'subscription_edit' => $this->buildSubscriptionEditResult($message, $state),
            'subscription_cancel' => $this->buildSubscriptionCancelResult($message, $state),
            'compound_transaction_create' => $this->buildCompoundTransactionCreateResult($message),
            'transaction_edit' => $this->buildTransactionEditResult($message, $state),
            'transaction_delete' => $this->buildTransactionDeleteResult($message, $state),
            'transaction_follow_up' => $this->buildQueryResult($classification['target_action'] ?? 'query_transactions', $message),
            default => ['handled' => false],
        };
    }

    public function metadataForResult(string $message, ?string $action, array $result, WhatsAppContact $contact): array
    {
        $metadata = $result['_conversation_metadata'] ?? [];

        if ($action === 'confirm_large_transaction' && ! empty($result['transaction_data'])) {
            $metadata['pending_intent'] = 'confirm_large_transaction';
            $metadata['pending_payload'] = [
                'transaction_data' => $result['transaction_data'],
            ];
            $metadata['reply_kind'] = 'confirmation_request';
            $metadata['clear_pending'] = false;
        }

        if (! isset($metadata['entities'])) {
            $metadata['entities'] = [];
        }

        if ($action === 'query_budgets' && isset($result['_resolved_message'])) {
            $metadata['entities']['budget_query_message'] = $result['_resolved_message'];
        }

        if (! array_key_exists('clear_pending', $metadata)) {
            $metadata['clear_pending'] = true;
        }

        return $metadata;
    }

    private function handleCancellation(array $state): array
    {
        if (($state['mode'] ?? 'idle') !== 'idle') {
            return [
                'handled' => true,
                'reply' => $this->composer->composeCancellationReply(),
                'action' => null,
                'metadata' => ['clear_pending' => true, 'reply_kind' => 'message'],
            ];
        }

        return [
            'handled' => true,
            'reply' => 'Sem problema. Se quiser, posso te mostrar seus orcamentos, saldo, metas ou registrar um gasto.',
            'action' => null,
            'metadata' => ['clear_pending' => false, 'reply_kind' => 'message'],
        ];
    }

    private function handleConfirmation(array $state): array
    {
        if (($state['mode'] ?? 'idle') !== 'awaiting_confirmation') {
            return [
                'handled' => true,
                'reply' => $this->composer->composeNeutralAcknowledgement($state['last_action'] ?? null, $state['last_entities'] ?? []),
                'action' => null,
                'metadata' => ['clear_pending' => false, 'reply_kind' => 'message'],
            ];
        }

        if (($state['pending_intent'] ?? null) === 'confirm_large_transaction' && ! empty($state['pending_payload']['transaction_data'])) {
            return [
                'handled' => false,
                'result' => [
                    'reply' => 'Perfeito, vou confirmar esse lancamento para voce.',
                    'action' => 'confirm_large_transaction',
                    'transaction_data' => $state['pending_payload']['transaction_data'],
                    '_conversation_metadata' => [
                        'clear_pending' => true,
                        'reply_kind' => 'action',
                    ],
                ],
            ];
        }

        if (($state['pending_intent'] ?? null) === 'delete_transaction' && ! empty($state['pending_payload']['transaction_data'])) {
            return [
                'handled' => false,
                'result' => [
                    'reply' => 'Perfeito, vou apagar essa transacao para voce.',
                    'action' => 'delete_transaction',
                    'transaction_data' => array_merge($state['pending_payload']['transaction_data'], ['confirmed' => true]),
                    '_conversation_metadata' => [
                        'clear_pending' => true,
                        'reply_kind' => 'action',
                    ],
                ],
            ];
        }

        if (($state['pending_intent'] ?? null) === 'delete_budget' && ! empty($state['pending_payload']['budget_data'])) {
            return [
                'handled' => false,
                'result' => [
                    'reply' => 'Perfeito, vou cancelar esse orcamento para voce.',
                    'action' => 'delete_budget',
                    'budget_data' => array_merge($state['pending_payload']['budget_data'], ['confirmed' => true]),
                    '_conversation_metadata' => [
                        'clear_pending' => true,
                        'reply_kind' => 'action',
                    ],
                ],
            ];
        }

        return [
            'handled' => true,
            'reply' => $this->composer->composePendingConfirmationPrompt(),
            'action' => null,
            'metadata' => ['clear_pending' => false, 'reply_kind' => 'message'],
        ];
    }

    private function buildQueryResult(string $action, string $message): array
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

    private function buildSavingsCreateResult(string $message): array
    {
        return [
            'handled' => false,
            'result' => [
                'reply' => '',
                'action' => 'create_savings_goal',
                'goal_data' => $this->savingsGoalMessageParser->parse($message) ?? [],
                '_resolved_message' => $message,
                '_conversation_metadata' => [
                    'clear_pending' => true,
                    'reply_kind' => 'action',
                ],
            ],
        ];
    }

    private function buildSavingsEditResult(string $message, array $state): array
    {
        return [
            'handled' => false,
            'result' => [
                'reply' => '',
                'action' => 'update_savings_goal',
                'goal_data' => $this->savingsGoalMessageParser->parseEdit($message, $this->recentEntityName($state, 'savings', 'goal_name')) ?? [],
                '_resolved_message' => $message,
                '_conversation_metadata' => [
                    'clear_pending' => true,
                    'reply_kind' => 'action',
                ],
            ],
        ];
    }

    private function buildSubscriptionCreateResult(string $message): array
    {
        return [
            'handled' => false,
            'result' => [
                'reply' => '',
                'action' => 'create_subscription',
                'subscription_data' => $this->subscriptionMessageParser->parse($message) ?? [],
                '_resolved_message' => $message,
                '_conversation_metadata' => [
                    'clear_pending' => true,
                    'reply_kind' => 'action',
                ],
            ],
        ];
    }

    private function buildSubscriptionEditResult(string $message, array $state): array
    {
        return [
            'handled' => false,
            'result' => [
                'reply' => '',
                'action' => 'update_subscription',
                'subscription_data' => $this->subscriptionMessageParser->parseEdit($message, $this->recentEntityName($state, 'subscriptions', 'subscription_name')) ?? [],
                '_resolved_message' => $message,
                '_conversation_metadata' => [
                    'clear_pending' => true,
                    'reply_kind' => 'action',
                ],
            ],
        ];
    }

    private function buildSubscriptionCancelResult(string $message, array $state): array
    {
        return [
            'handled' => false,
            'result' => [
                'reply' => '',
                'action' => 'cancel_subscription',
                'subscription_data' => $this->subscriptionMessageParser->parseCancel($message, $this->recentEntityName($state, 'subscriptions', 'subscription_name')) ?? [],
                '_resolved_message' => $message,
                '_conversation_metadata' => [
                    'clear_pending' => true,
                    'reply_kind' => 'action',
                ],
            ],
        ];
    }

    private function buildBudgetEditResult(string $message, array $state): array
    {
        return [
            'handled' => false,
            'result' => [
                'reply' => '',
                'action' => 'update_budget',
                'budget_data' => $this->budgetMessageParser->parseEdit($message, $this->recentEntityName($state, 'budget', 'category_name'), $this->recentBudgetPeriod($state)) ?? [],
                '_resolved_message' => $message,
                '_conversation_metadata' => [
                    'clear_pending' => true,
                    'reply_kind' => 'action',
                ],
            ],
        ];
    }

    private function buildBudgetDeleteResult(string $message, array $state): array
    {
        return [
            'handled' => false,
            'result' => [
                'reply' => '',
                'action' => 'delete_budget',
                'budget_data' => $this->budgetMessageParser->parseDelete($message, $this->recentEntityName($state, 'budget', 'category_name'), $this->recentBudgetPeriod($state)) ?? [],
                '_resolved_message' => $message,
                '_conversation_metadata' => [
                    'clear_pending' => true,
                    'reply_kind' => 'action',
                ],
            ],
        ];
    }

    private function buildTransactionEditResult(string $message, array $state): array
    {
        return [
            'handled' => false,
            'result' => [
                'reply' => '',
                'action' => 'edit_transaction',
                'transaction_data' => $this->transactionActionMessageParser->parseEdit($message, $state) ?? [],
                '_resolved_message' => $message,
                '_conversation_metadata' => [
                    'clear_pending' => true,
                    'reply_kind' => 'action',
                ],
            ],
        ];
    }

    private function buildCompoundTransactionCreateResult(string $message): array
    {
        return [
            'handled' => false,
            'result' => [
                'reply' => '',
                'action' => 'create_transaction',
                'transaction_data' => [],
                '_resolved_message' => $message,
                '_conversation_metadata' => [
                    'clear_pending' => true,
                    'reply_kind' => 'action',
                ],
            ],
        ];
    }

    private function buildTransactionDeleteResult(string $message, array $state): array
    {
        return [
            'handled' => false,
            'result' => [
                'reply' => '',
                'action' => 'delete_transaction',
                'transaction_data' => $this->transactionActionMessageParser->parseDelete($message, $state) ?? [],
                '_resolved_message' => $message,
                '_conversation_metadata' => [
                    'clear_pending' => true,
                    'reply_kind' => 'action',
                ],
            ],
        ];
    }

    private function recentEntityName(array $state, string $topic, string $field): ?string
    {
        if (($state['last_entities']['topic'] ?? null) === $topic && ! empty($state['last_entities'][$field])) {
            return (string) $state['last_entities'][$field];
        }

        foreach (($state['recent_contexts'] ?? []) as $context) {
            if (($context['entities']['topic'] ?? null) === $topic && ! empty($context['entities'][$field])) {
                return (string) $context['entities'][$field];
            }
        }

        return null;
    }

    private function recentBudgetPeriod(array $state): array
    {
        $entities = ($state['last_entities']['topic'] ?? null) === 'budget'
            ? ($state['last_entities'] ?? [])
            : [];

        if ($entities === []) {
            foreach (($state['recent_contexts'] ?? []) as $context) {
                $candidate = $context['entities'] ?? [];
                if (($candidate['topic'] ?? null) === 'budget') {
                    $entities = $candidate;
                    break;
                }
            }
        }

        return [
            'period' => ($entities['month'] ?? null) ? 'monthly' : 'yearly',
            'year' => $entities['year'] ?? now()->year,
            'month' => $entities['month'] ?? now()->month,
        ];
    }

    private function handleClarification(string $message, array $state): ?array
    {
        return match ($state['pending_intent'] ?? null) {
            'update_budget_category' => $this->buildBudgetClarificationResult('update_budget', $message, $state),
            'delete_budget_category' => $this->buildBudgetClarificationResult('delete_budget', $message, $state),
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
}
