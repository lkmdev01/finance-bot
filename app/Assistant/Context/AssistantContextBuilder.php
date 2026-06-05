<?php

namespace App\Assistant\Context;

use App\Assistant\DTO\AssistantContextDTO;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\ConversationStateService;

class AssistantContextBuilder
{
    public function __construct(
        private readonly ConversationStateService $stateService,
    ) {}

    public function build(User $user, WhatsAppContact $contact): AssistantContextDTO
    {
        $state = $this->stateService->getState($contact);

        return new AssistantContextDTO(
            user: $user,
            contact: $contact,
            state: $state,
            timezone: config('app.timezone', 'America/Sao_Paulo'),
            currentMonth: now()->format('Y-m'),
            currentYear: (int) now()->year,
            lastAction: $state['last_action'] ?? null,
            pendingAction: $state['pending_intent'] ?? null,
        );
    }
}
