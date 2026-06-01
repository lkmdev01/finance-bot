<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\Resolvers\ClarificationResolver;
use App\Services\WhatsApp\Resolvers\ConfirmationResolver;
use App\Services\WhatsApp\Resolvers\DomainRoutingResolver;
use App\Services\WhatsApp\Resolvers\PreflightMessageResolver;

class ConversationOrchestrator
{
    public function __construct(
        private readonly MessageClassifier $classifier,
        private readonly ConversationStateService $stateService,
        private readonly ClarificationResolver $clarificationResolver,
        private readonly ConfirmationResolver $confirmationResolver,
        private readonly DomainRoutingResolver $domainRoutingResolver,
        private readonly PreflightMessageResolver $preflightMessageResolver,
    ) {}

    public function beforeAI(string $message, User $user, WhatsAppContact $contact): array
    {
        $state = $this->stateService->getState($contact);
        $classification = $this->classifier->classify($message, $state);
        $domain = $classification['domain'] ?? null;

        if (($classification['kind'] ?? null) === 'recurring_transaction_needs_amount') {
            return [
                'handled' => true,
                'reply' => 'Entendi a recorrencia. Agora me diga o valor para eu terminar o cadastro.',
                'action' => null,
                'classification' => $classification['kind'],
                'domain' => $domain,
                'metadata' => [
                    'clear_pending' => false,
                    'reply_kind' => 'message',
                    'pending_intent' => 'create_recurring_transaction_amount',
                    'pending_mode' => 'awaiting_clarification',
                    'pending_payload' => [
                        'recurring_data' => $classification['payload'] ?? [],
                    ],
                ],
            ];
        }

        if (($classification['kind'] ?? null) === 'reminder_needs_schedule') {
            return [
                'handled' => true,
                'reply' => 'Entendi o lembrete. Agora me diga quando devo te lembrar.',
                'action' => null,
                'classification' => $classification['kind'],
                'domain' => $domain,
                'metadata' => [
                    'clear_pending' => false,
                    'reply_kind' => 'message',
                    'pending_intent' => 'create_reminder_schedule',
                    'pending_mode' => 'awaiting_clarification',
                    'pending_payload' => [
                        'reminder_data' => $classification['payload'] ?? [],
                    ],
                ],
            ];
        }

        if (($classification['kind'] ?? null) === 'note_needs_content') {
            return [
                'handled' => true,
                'reply' => "Entendi. O que voce quer que eu salve na nota?\n\nExemplos:\n- anota: ideia para o projeto X\n- nota: lembrar de perguntar sobre o contrato",
                'action' => null,
                'classification' => $classification['kind'],
                'domain' => $domain,
                'metadata' => [
                    'clear_pending' => false,
                    'reply_kind' => 'message',
                    'pending_intent' => 'create_note_content',
                    'pending_mode' => 'awaiting_clarification',
                    'pending_payload' => [
                        'note_data' => $classification['payload'] ?? [],
                    ],
                ],
            ];
        }

        if (($state['mode'] ?? 'idle') === 'awaiting_clarification'
            && $this->clarificationResolver->shouldResolve($classification['kind'], $state)) {
            $clarification = $this->clarificationResolver->resolve($message, $state);
            if ($clarification !== null) {
                $clarification['classification'] = $classification['kind'];

                return $clarification;
            }
        }

        $decision = $this->preflightMessageResolver->resolve($classification['kind'], $state, $user)
            ?? ($classification['kind'] === 'confirmation'
                ? $this->confirmationResolver->resolve($state)
                : $this->domainRoutingResolver->resolve($classification['kind'], $message, $state));

        $decision['classification'] = $classification['kind'];
        $decision['domain'] = $domain;

        return $decision;
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
}
