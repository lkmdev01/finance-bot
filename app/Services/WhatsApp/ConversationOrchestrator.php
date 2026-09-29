<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\Resolvers\PreflightMessageResolver;
use Illuminate\Support\Str;

class ConversationOrchestrator
{
    public function __construct(
        private readonly ConversationStateService $stateService,
        private readonly PreflightMessageResolver $preflightMessageResolver,
    ) {}

    /**
     * Resolve mensagens sociais e de navegação sem depender do provedor de IA.
     * Intenções financeiras continuam no agente estruturado.
     *
     * @return array<string, mixed>|null
     */
    public function beforeAI(string $message, User $user, WhatsAppContact $contact): ?array
    {
        $state = $this->stateService->getState($contact);
        $normalized = Str::of($message)
            ->ascii()
            ->lower()
            ->replaceMatches('/[!?.]+/u', '')
            ->squish()
            ->toString();

        $classification = $this->classifyLocalMessage($normalized, $state);
        if ($classification === null) {
            return null;
        }

        $decision = $this->preflightMessageResolver->resolve($classification, $state, $user);
        if ($decision === null) {
            return null;
        }

        $decision['classification'] = $classification;
        $decision['domain'] = 'general';

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

    private function classifyLocalMessage(string $message, array $state): ?string
    {
        if (($state['mode'] ?? 'idle') === 'awaiting_clarification'
            && ($state['pending_intent'] ?? null) === 'help_choice') {
            if ($this->containsAny($message, ['suporte', 'email', 'e-mail', 'humano', 'atendimento'])) {
                return 'help_support';
            }

            if ($this->containsAny($message, ['comando', 'funcao', 'funcoes', 'recurso', 'exemplo'])) {
                return 'help_commands';
            }
        }

        if (in_array($message, ['oi', 'ola', 'bom dia', 'boa tarde', 'boa noite', 'e ai'], true)) {
            return 'greeting';
        }

        if (in_array($message, ['ajuda', 'como voce pode me ajudar', 'o que voce faz'], true)) {
            return 'help';
        }

        if (in_array($message, ['comandos', 'ver comandos', 'mostrar comandos'], true)) {
            return 'help_commands';
        }

        if (in_array($message, ['suporte', 'falar com suporte', 'suporte humano'], true)) {
            return 'help_support';
        }

        if ($this->containsAny($message, ['link do painel', 'abrir painel', 'link do site', 'acessar painel'])) {
            return 'dashboard_link';
        }

        if (in_array($message, ['tudo bem', 'como voce esta', 'como vai'], true)) {
            return 'small_talk';
        }

        if (in_array($message, ['obrigado', 'obrigada', 'valeu', 'agradecido'], true)) {
            return 'gratitude';
        }

        if (($state['mode'] ?? 'idle') === 'idle'
            && in_array($message, ['ok', 'beleza', 'perfeito', 'entendi'], true)) {
            return 'acknowledgement';
        }

        if (in_array($message, ['cancelar', 'cancela', 'deixa pra la'], true)) {
            return 'cancellation';
        }

        return null;
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function containsAny(string $message, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
