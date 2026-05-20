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
    ) {}

    public function beforeAI(string $message, User $user, WhatsAppContact $contact): array
    {
        $state = $this->stateService->getState($contact);
        $classification = $this->classifier->classify($message, $state);

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
            'budget_query' => [
                'handled' => false,
                'result' => [
                    'reply' => '',
                    'action' => 'query_budgets',
                    'transaction_data' => null,
                    '_resolved_message' => $message,
                    '_conversation_metadata' => [
                        'clear_pending' => true,
                        'reply_kind' => 'query',
                    ],
                ],
            ],
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
            'reply' => 'Sem problema. Se quiser, posso te mostrar seus orçamentos, saldo ou registrar um gasto.',
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
                    'reply' => 'Perfeito, vou confirmar esse lançamento para você.',
                    'action' => 'confirm_large_transaction',
                    'transaction_data' => $state['pending_payload']['transaction_data'],
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
}
