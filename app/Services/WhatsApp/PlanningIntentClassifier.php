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
        if ($this->looksLikeSubscriptionCreate($originalMessage, $normalizedMessage)) {
            return ['kind' => 'subscription_create', 'normalized' => $normalizedMessage];
        }

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
            && $this->savingsGoalMessageParser->parse($originalMessage) !== null;
    }

    private function looksLikeSubscriptionCreate(string $originalMessage, string $normalizedMessage): bool
    {
        return $this->subscriptionMessageParser->looksLikeCreateIntent($normalizedMessage)
            && $this->subscriptionMessageParser->parse($originalMessage) !== null;
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
            return $this->savingsGoalMessageParser->parseEdit($originalMessage, $fallbackName) !== null;
        }

        if (($state['last_action'] ?? null) !== 'query_savings') {
            return false;
        }

        if (! $this->containsAnyText($normalizedMessage, ['editar', 'edita', 'alterar', 'altera', 'ajustar', 'ajusta', 'mudar', 'muda', 'atualizar', 'atualiza'])) {
            return false;
        }

        return $fallbackName !== null
            && $this->savingsGoalMessageParser->parseEdit('meta '.$fallbackName.' '.$originalMessage, $fallbackName) !== null;
    }

    private function looksLikeSubscriptionEdit(string $originalMessage, string $normalizedMessage, array $state): bool
    {
        $fallbackName = ($state['last_entities']['topic'] ?? null) === 'subscriptions'
            ? $this->contextResolver->recentEntityName($state, 'subscriptions', 'subscription_name')
            : null;

        if ($this->subscriptionMessageParser->looksLikeEditIntent($normalizedMessage)) {
            return $this->subscriptionMessageParser->parseEdit($originalMessage, $fallbackName) !== null;
        }

        if (($state['last_entities']['topic'] ?? null) !== 'subscriptions') {
            return false;
        }

        if (! $this->containsAnyText($normalizedMessage, ['editar', 'edita', 'alterar', 'altera', 'ajustar', 'ajusta', 'mudar', 'muda', 'atualizar', 'atualiza'])) {
            return false;
        }

        return $this->subscriptionMessageParser->parseEdit('assinatura '.($fallbackName ?? '').' '.$originalMessage, $fallbackName) !== null;
    }

    private function looksLikeSubscriptionCancel(string $originalMessage, string $normalizedMessage, array $state): bool
    {
        $fallbackName = ($state['last_entities']['topic'] ?? null) === 'subscriptions'
            ? $this->contextResolver->recentEntityName($state, 'subscriptions', 'subscription_name')
            : null;

        if ($this->subscriptionMessageParser->looksLikeCancelIntent($normalizedMessage)) {
            return $this->subscriptionMessageParser->parseCancel($originalMessage, $fallbackName) !== null;
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

    private function looksLikeNamedFollowUp(string $message): bool
    {
        if (! preg_match('/^(?:e\s+)?(?:o|a|os|as)?\s*([\p{L}\p{N} _-]+)$/u', $message, $matches)) {
            return false;
        }

        $term = trim($matches[1] ?? '');

        return $term !== '' && count(array_filter(explode(' ', $term))) <= 4;
    }
}
