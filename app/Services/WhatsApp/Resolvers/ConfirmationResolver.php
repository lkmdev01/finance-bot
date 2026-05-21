<?php

namespace App\Services\WhatsApp\Resolvers;

use App\Services\WhatsApp\ResponseComposer;

class ConfirmationResolver
{
    public function __construct(
        private readonly ResponseComposer $composer,
    ) {}

    public function resolve(array $state): array
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
            return $this->actionResult(
                'confirm_large_transaction',
                'transaction_data',
                $state['pending_payload']['transaction_data'],
                'Perfeito, vou confirmar esse lançamento para você.'
            );
        }

        if (($state['pending_intent'] ?? null) === 'delete_transaction' && ! empty($state['pending_payload']['transaction_data'])) {
            return $this->actionResult(
                'delete_transaction',
                'transaction_data',
                array_merge($state['pending_payload']['transaction_data'], ['confirmed' => true]),
                'Perfeito, vou apagar essa transação para você.'
            );
        }

        if (($state['pending_intent'] ?? null) === 'delete_budget' && ! empty($state['pending_payload']['budget_data'])) {
            return $this->actionResult(
                'delete_budget',
                'budget_data',
                array_merge($state['pending_payload']['budget_data'], ['confirmed' => true]),
                'Perfeito, vou cancelar esse orçamento para você.'
            );
        }

        return [
            'handled' => true,
            'reply' => $this->composer->composePendingConfirmationPrompt(),
            'action' => null,
            'metadata' => ['clear_pending' => false, 'reply_kind' => 'message'],
        ];
    }

    private function actionResult(string $action, string $payloadKey, array $payload, string $reply): array
    {
        return [
            'handled' => false,
            'result' => [
                'reply' => $reply,
                'action' => $action,
                $payloadKey => $payload,
                '_conversation_metadata' => [
                    'clear_pending' => true,
                    'reply_kind' => 'action',
                ],
            ],
        ];
    }
}
