<?php

namespace App\Services\WhatsApp\Resolvers;

use App\Models\User;
use App\Services\WhatsApp\ResponseComposer;

class PreflightMessageResolver
{
    public function __construct(
        private readonly ResponseComposer $composer,
    ) {}

    public function resolve(string $classification, array $state, User $user): ?array
    {
        return match ($classification) {
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
            'cancellation' => $this->resolveCancellation($state),
            default => null,
        };
    }

    private function resolveCancellation(array $state): array
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
            'reply' => 'Sem problema. Se quiser, posso te mostrar seus orçamentos, saldo, metas ou registrar um gasto.',
            'action' => null,
            'metadata' => ['clear_pending' => false, 'reply_kind' => 'message'],
        ];
    }
}
