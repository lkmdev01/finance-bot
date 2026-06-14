<?php

namespace App\Services\WhatsApp;

use App\Services\WhatsApp\Support\NormalizesWhatsAppText;

class PlanningIntentClassifier
{
    use NormalizesWhatsAppText;

    public function __construct(
        private readonly SavingsGoalMessageParser $savingsGoalMessageParser,
        private readonly SubscriptionMessageParser $subscriptionMessageParser,
        private readonly CreditCardMessageParser $creditCardMessageParser,
        private readonly ConversationContextResolver $contextResolver,
    ) {}

    public function classify(string $originalMessage, string $normalizedMessage, array $state): ?array
    {
        if ($this->looksLikeCreditCardCreate($originalMessage, $normalizedMessage)) {
            return ['kind' => 'credit_card_create', 'normalized' => $normalizedMessage];
        }

        if ($this->looksLikeSubscriptionCancel($originalMessage, $normalizedMessage, $state)) {
            return ['kind' => 'subscription_cancel', 'normalized' => $normalizedMessage];
        }

        if ($this->looksLikeSavingsEdit($originalMessage, $normalizedMessage, $state)) {
            return ['kind' => 'savings_edit', 'normalized' => $normalizedMessage];
        }

        if ($this->looksLikeSubscriptionEdit($originalMessage, $normalizedMessage, $state)) {
            return ['kind' => 'subscription_edit', 'normalized' => $normalizedMessage];
        }

        if ($this->looksLikeSubscriptionCreate($originalMessage, $normalizedMessage)) {
            return ['kind' => 'subscription_create', 'normalized' => $normalizedMessage];
        }

        if (($state['last_action'] ?? null) === 'query_subscriptions'
            && in_array($normalizedMessage, ['ativas', 'ativa', 'cancelados', 'canceladas', 'cancelada', 'inativas', 'inativa', 'impacto'], true)) {
            return ['kind' => 'subscription_query', 'normalized' => $normalizedMessage];
        }

        if ($this->looksLikeSavingsCreate($originalMessage, $normalizedMessage)) {
            return ['kind' => 'savings_create', 'normalized' => $normalizedMessage];
        }

        if ($this->looksLikeSavingsQuery($normalizedMessage, $state)) {
            return ['kind' => 'savings_query', 'normalized' => $normalizedMessage];
        }

        if ($this->looksLikeSubscriptionQuery($normalizedMessage, $state)) {
            return ['kind' => 'subscription_query', 'normalized' => $normalizedMessage];
        }

        if ($this->looksLikeProjectionQuery($normalizedMessage, $state)) {
            return ['kind' => 'projection_query', 'normalized' => $normalizedMessage];
        }

        if ($this->looksLikeCreditCardQuery($originalMessage, $normalizedMessage)) {
            return ['kind' => 'credit_card_query', 'normalized' => $normalizedMessage];
        }

        return null;
    }

    private function looksLikeSavingsCreate(string $originalMessage, string $normalizedMessage): bool
    {
        return $this->savingsGoalMessageParser->looksLikeCreateIntent($normalizedMessage)
            && $this->savingsGoalMessageParser->parsePartialCreate($originalMessage) !== null;
    }

    private function looksLikeSubscriptionCreate(string $originalMessage, string $normalizedMessage): bool
    {
        return $this->subscriptionMessageParser->looksLikeCreateIntent($normalizedMessage)
            && $this->subscriptionMessageParser->parsePartialCreate($originalMessage) !== null;
    }

    private function looksLikeCreditCardCreate(string $originalMessage, string $normalizedMessage): bool
    {
        return $this->creditCardMessageParser->looksLikeCreateIntent($normalizedMessage)
            && $this->creditCardMessageParser->parseCreate($originalMessage) !== null;
    }

    private function looksLikeSavingsEdit(string $originalMessage, string $normalizedMessage, array $state): bool
    {
        $fallbackName = ($state['last_entities']['topic'] ?? null) === 'savings'
            ? $this->contextResolver->recentEntityName($state, 'savings', 'goal_name')
            : null;

        if ($this->savingsGoalMessageParser->looksLikeEditIntent($normalizedMessage)) {
            return $this->savingsGoalMessageParser->parseEdit($originalMessage, $fallbackName) !== null || $fallbackName !== null;
        }

        if (($state['last_action'] ?? null) !== 'query_savings') {
            return false;
        }

        if (! $this->containsAnyText($normalizedMessage, ['editar', 'edita', 'alterar', 'altera', 'ajustar', 'ajusta', 'mudar', 'muda', 'atualizar', 'atualiza'])) {
            return false;
        }

        return $fallbackName !== null;
    }

    private function looksLikeSubscriptionEdit(string $originalMessage, string $normalizedMessage, array $state): bool
    {
        $fallbackName = ($state['last_entities']['topic'] ?? null) === 'subscriptions'
            ? $this->contextResolver->recentEntityName($state, 'subscriptions', 'subscription_name')
            : null;

        if ($this->subscriptionMessageParser->looksLikeEditIntent($normalizedMessage)) {
            return true;
        }

        if (($state['last_entities']['topic'] ?? null) !== 'subscriptions') {
            return false;
        }

        if (! $this->containsAnyText($normalizedMessage, ['editar', 'edita', 'alterar', 'altera', 'ajustar', 'ajusta', 'mudar', 'muda', 'atualizar', 'atualiza'])) {
            return false;
        }

        return $fallbackName !== null;
    }

    private function looksLikeSubscriptionCancel(string $originalMessage, string $normalizedMessage, array $state): bool
    {
        $fallbackName = ($state['last_entities']['topic'] ?? null) === 'subscriptions'
            ? $this->contextResolver->recentEntityName($state, 'subscriptions', 'subscription_name')
            : null;

        if ($this->subscriptionMessageParser->looksLikeCancelIntent($normalizedMessage)) {
            return true;
        }

        if (($state['last_entities']['topic'] ?? null) !== 'subscriptions') {
            return false;
        }

        if (! $this->containsAnyText($normalizedMessage, ['cancelar', 'cancela', 'desativar', 'desativa', 'pausar', 'pausa'])) {
            return false;
        }

        return $fallbackName !== null;
    }

    private function looksLikeSavingsQuery(string $message, array $state): bool
    {
        if ($this->belongsToAnotherDomain($message)) {
            return false;
        }

        if ($this->containsAnyText($message, ['meta', 'metas', 'objetivo', 'objetivos', 'poupanca'])) {
            return ! $this->containsAnyText($message, ['criar', 'crie', 'nova', 'novo', 'definir', 'defina', 'cadastrar', 'cadastre']);
        }

        if (($state['last_action'] ?? null) !== 'query_savings') {
            return false;
        }

        if ($this->containsAnyText($message, ['assinatura', 'assinaturas', 'mensalidade', 'mensalidades', 'projecao', 'projecoes', 'orcamento'])) {
            return false;
        }

        if (preg_match('/\b(quais|qual|me mostra|mostrar|listar|liste|lista|quero ver)\b/u', $message)) {
            return true;
        }

        return $this->looksLikeNamedFollowUp($message);
    }

    private function looksLikeSubscriptionQuery(string $message, array $state): bool
    {
        if ($this->belongsToAnotherDomain($message)) {
            return false;
        }

        if ($this->containsAnyText($message, [
            'cancelar', 'cancela', 'desativar', 'desativa', 'pausar', 'pausa',
            'editar', 'edita', 'alterar', 'altera', 'ajustar', 'ajusta', 'mudar', 'muda', 'atualizar', 'atualiza',
        ])) {
            return false;
        }

        if ($this->containsAnyText($message, ['assinatura', 'assinaturas', 'mensalidade', 'mensalidades'])) {
            return ! $this->containsAnyText($message, ['criar', 'crie', 'nova', 'novo', 'definir', 'defina', 'cadastrar', 'cadastre']);
        }

        if (($state['last_action'] ?? null) !== 'query_subscriptions') {
            return false;
        }

        if ($this->containsAnyText($message, ['ativas', 'ativa', 'canceladas', 'cancelados', 'cancelada', 'inativas', 'inativa', 'impacto'])) {
            return true;
        }

        if ($this->containsAnyText($message, ['projecao', 'projecoes', 'daqui a', 'proximo mes', 'saldo futuro'])) {
            return false;
        }

        if (preg_match('/\b(quais|qual|me mostra|mostrar|listar|liste|lista|quero ver)\b/u', $message)) {
            return true;
        }

        return $this->looksLikeNamedFollowUp($message)
            || $this->containsAnyText($message, ['vence', 'vencem', 'proxima', 'proximo vencimento']);
    }

    private function looksLikeProjectionQuery(string $message, array $state): bool
    {
        if ($this->containsAnyText($message, ['projecao', 'projecoes', 'futuro', 'daqui a', 'proximo mes'])) {
            return true;
        }

        if (($state['last_action'] ?? null) !== 'query_projections') {
            return false;
        }

        return $this->containsAnyText($message, ['daqui a', 'proximo', 'depois', 'seguinte']);
    }

    private function looksLikeCreditCardQuery(string $originalMessage, string $normalizedMessage): bool
    {
        return $this->creditCardMessageParser->looksLikeQueryIntent($normalizedMessage);
    }

    private function belongsToAnotherDomain(string $message): bool
    {
        return $this->containsAnyText($message, [
            'nota',
            'notas',
            'anota',
            'anotar',
            'anote',
            'arquivo',
            'arquivos',
            'documento',
            'documentos',
            'drive',
            'foto',
            'fotos',
            'imagem',
            'imagens',
            'audio',
            'audios',
            'lembrete',
            'lembretes',
            'me lembra',
            'me lembre',
        ]);
    }

    private function looksLikeNamedFollowUp(string $message): bool
    {
        if (! preg_match('/^(?:e\s+)?(?:(?:o|a|os|as)\s+)?([\p{L}\p{N} _-]+)$/u', $message, $matches)) {
            return false;
        }

        $term = trim($matches[1] ?? '');

        if ($term === '' || $this->isGenericContextPhrase($term)) {
            return false;
        }

        $tokens = array_values(array_filter(preg_split('/\s+/u', $term) ?: []));
        if ($tokens === [] || count($tokens) > 4) {
            return false;
        }

        $filtered = array_values(array_filter($tokens, fn (string $token) => ! in_array($token, [
            'o', 'a', 'os', 'as', 'um', 'uma',
            'meu', 'minha', 'meus', 'minhas',
            'esse', 'essa', 'esses', 'essas',
            'este', 'esta', 'estes', 'estas',
            'isso', 'isto', 'ele', 'ela', 'eles', 'elas',
            'so', 'só', 'aqui', 'ali', 'la', 'lá',
        ], true)));

        if ($filtered === []) {
            return false;
        }

        foreach ($filtered as $token) {
            if (mb_strlen($token) >= 4) {
                return true;
            }
        }

        return false;
    }

    private function isGenericContextPhrase(string $message): bool
    {
        return in_array($message, [
            'oi',
            'ola',
            'bom dia',
            'boa tarde',
            'boa noite',
            'como voce esta',
            'como vc esta',
            'como vai',
            'tudo bem',
            'obrigado',
            'obrigada',
            'valeu',
            'show',
            'top',
            'ajuda',
            'como funciona',
            'como voce pode me ajudar',
            'como vc pode me ajudar',
            'como pode me ajudar',
        ], true);
    }
}
