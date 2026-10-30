<?php

namespace App\Services;

use App\Exceptions\AiUserTokenLimitExceeded;
use App\Models\User;
use App\Models\WhatsAppContact;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\RateLimitedException;

class WhatsAppMessageProcessor
{
    public function __construct(
        private readonly AIService $aiService,
        private readonly ?SpecializedAiFallbackService $fallback = null,
    ) {}

    /**
     * Processa mensagem do WhatsApp
     */
    public function process(string $message, User $user, WhatsAppContact $contact): array
    {
        try {
            if (config('ai.use_sdk', false)) {
                return $this->processWithSdk($message, $user, $contact);
            }

            $result = $this->aiService->processMessage($message, $user, $contact);
        } catch (AiUserTokenLimitExceeded $exception) {
            return [
                'action' => null,
                'reply' => $this->tokenLimitMessage($exception),
            ];
        }

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

        try {
            $result = ($this->fallback ?? app(SpecializedAiFallbackService::class))->prompt(
                $user,
                $agent->forUser($contact),
                $message,
                'financial',
            );
        } catch (RateLimitedException $exception) {
            Log::notice('Modelos principal e de contingência indisponíveis para o assistente financeiro', [
                'user_id' => $user->id,
                'exception' => $exception::class,
            ]);

            return [
                'action' => null,
                'reply' => 'Poxa, não consegui responder direito agora. Não fiz nenhuma alteração financeira. Pode me dizer de novo o que você precisa? Vou tentar ajudar.',
            ];
        }

        // Garantir compatibilidade com o formato array retornado anteriormente
        if (! is_array($result)) {
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

    private function tokenLimitMessage(AiUserTokenLimitExceeded $exception): string
    {
        return 'Você atingiu o limite mensal de '.number_format($exception->limit, 0, ',', '.').' tokens de IA. Ele será renovado no próximo mês.';
    }
}
