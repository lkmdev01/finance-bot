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
        if (config('ai.use_sdk', false)) {
            return $this->processWithSdk($message, $user, $contact);
        }

        $result = $this->aiService->processMessage($message, $user, $contact);

        if (($result['action'] ?? null) === 'create_budget'
            && ! isset($result['budget_data'])
            && isset($result['transaction_data'])) {
            $result['budget_data'] = $result['transaction_data'];
        }

        return $result;
    }

    private function processWithSdk(string $message, User $user, WhatsAppContact $contact): array
    {
        /** @var \App\Ai\FinancialAgent $agent */
        $agent = app(\App\Ai\FinancialAgent::class, [
            'user' => $user,
            'contact' => $contact,
        ]);

        $response = $agent
            ->forUser($contact)
            ->prompt($message);

        /** @var \Laravel\Ai\Responses\StructuredAgentResponse $response */
        $result = $response->toArray();

        // Garantir compatibilidade com o formato array retornado anteriormente
        if (!is_array($result)) {
            $result = (array) $result;
        }

        if (isset($result['conversation_metadata']) && is_array($result['conversation_metadata'])) {
            $result['_conversation_metadata'] = $result['conversation_metadata'];
            unset($result['conversation_metadata']);
        }

        if (($result['action'] ?? null) === 'create_budget'
            && ! isset($result['budget_data'])
            && isset($result['transaction_data'])) {
            $result['budget_data'] = $result['transaction_data'];
        }

        return $result;
    }
}
