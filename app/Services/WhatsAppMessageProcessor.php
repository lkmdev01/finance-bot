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
        // Processa a mensagem com IA
        $result = $this->aiService->processMessage($message, $user, $contact);
        
        // Atualiza contexto do contato
        $context = $contact->context ?? [];
        $context[] = [
            'message' => $message,
            'reply' => $result['reply'],
            'action' => $result['action'] ?? null,
            'timestamp' => now()->toIso8601String(),
        ];
        $contact->update(['context' => $context]);
        
        return $result;
    }
}
