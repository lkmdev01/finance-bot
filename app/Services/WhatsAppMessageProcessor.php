<?php

namespace App\Services;

use App\Models\User;
use App\Models\WhatsAppContact;

class WhatsAppMessageProcessor
{
    public function __construct(
        private readonly AIService $aiService
    ) {}

    /**
     * Processa mensagem do WhatsApp
     */
    public function process(string $message, User $user, WhatsAppContact $contact): array
    {
        $result = $this->aiService->processMessage($message, $user, $contact);

        if (($result['action'] ?? null) === 'create_budget'
            && ! isset($result['budget_data'])
            && isset($result['transaction_data'])) {
            $result['budget_data'] = $result['transaction_data'];
        }

        return $result;
    }
}
