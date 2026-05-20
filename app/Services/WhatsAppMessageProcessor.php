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
        return $this->aiService->processMessage($message, $user, $contact);
    }
}
