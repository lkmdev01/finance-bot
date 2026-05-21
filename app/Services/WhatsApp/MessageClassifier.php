<?php

namespace App\Services\WhatsApp;

class MessageClassifier
{
    public function __construct(
        private readonly SavingsGoalMessageParser $savingsGoalMessageParser,
        private readonly SubscriptionMessageParser $subscriptionMessageParser
    ) {}

    public function classify(string $message, array $state = []): array
    {
        $normalized = $this->normalize($message);
        $stripped = preg_replace('/[!?.]+/u', '', $normalized) ?? $normalized;

        if ($this->isGreeting($stripped)) {
            return ['kind' => 'greeting', 'normalized' => $stripped];
        }

        if ($this->looksLikeSubscriptionCancel($message, $stripped, $state)) {
            return ['kind' => 'subscription_cancel', 'normalized' => $stripped];
        }

        if ($this->looksLikeSavingsEdit($message, $stripped, $state)) {
            return ['kind' => 'savings_edit', 'normalized' => $stripped];
        }

        if ($this->looksLikeSubscriptionEdit($message, $stripped, $state)) {
            return ['kind' => 'subscription_edit', 'normalized' => $stripped];
        }

        if ($this->isCancellation($stripped)) {
            return ['kind' => 'cancellation', 'normalized' => $stripped];
        }

        if ($this->isShortAcknowledgement($stripped)) {
            $kind = ($state['mode'] ?? 'idle') === 'awaiting_confirmation'
                ? 'confirmation'
                : 'acknowledgement';

            return ['kind' => $kind, 'normalized' => $stripped];
        }

        if (($state['last_action'] ?? null) === 'query_subscriptions'
            && in_array($stripped, ['ativas', 'ativa', 'cancelados', 'canceladas', 'cancelada', 'inativas', 'inativa', 'impacto'], true)
        ) {
            return ['kind' => 'subscription_query', 'normalized' => $stripped];
        }

        if ($this->looksLikeSavingsCreate($message, $stripped)) {
            return ['kind' => 'savings_create', 'normalized' => $stripped];
        }

        if ($this->looksLikeSubscriptionCreate($message, $stripped)) {
            return ['kind' => 'subscription_create', 'normalized' => $stripped];
        }

        if ($this->looksLikeBudgetQuery($stripped, $state)) {
            return ['kind' => 'budget_query', 'normalized' => $stripped];
        }

        if ($this->looksLikeSavingsQuery($stripped, $state)) {
            return ['kind' => 'savings_query', 'normalized' => $stripped];
        }

        if ($this->looksLikeSubscriptionQuery($stripped, $state)) {
            return ['kind' => 'subscription_query', 'normalized' => $stripped];
        }

        if ($this->looksLikeProjectionQuery($stripped, $state)) {
            return ['kind' => 'projection_query', 'normalized' => $stripped];
        }

        if ($this->looksLikeTransactionFollowUp($stripped, $state)) {
            return [
                'kind' => 'transaction_follow_up',
                'target_action' => $state['last_action'] ?? 'query_transactions',
                'normalized' => $stripped,
            ];
        }

        return ['kind' => 'default', 'normalized' => $stripped];
    }

    private function isGreeting(string $message): bool
    {
        return in_array($message, ['oi', 'ola', 'bom dia', 'boa tarde', 'boa noite', 'e ai', 'hey', 'opa'], true);
    }

    private function isShortAcknowledgement(string $message): bool
    {
        return in_array($message, ['ok', 'okay', 'blz', 'beleza', 'certo', 'sim', 'claro', 'pode', 'pode sim', 'perfeito', 'entendi', 'isso'], true);
    }

    private function isCancellation(string $message): bool
    {
        if (in_array($message, ['cancelar', 'cancela', 'deixa', 'deixa pra la', 'nao', 'nÃƒÂ£o'], true)) {
            return true;
        }

        foreach (['nao quero', 'nÃƒÂ£o quero', 'agora nao', 'agora nÃƒÂ£o', 'nao precisa', 'nÃƒÂ£o precisa'] as $phrase) {
            if ($message === $phrase || str_starts_with($message, $phrase . ' ')) {
                return true;
            }
        }

        return false;
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

    private function looksLikeSavingsEdit(string $originalMessage, string $normalizedMessage, array $state): bool
    {
        $fallbackName = $this->recentEntityName($state, 'savings', 'goal_name');

        if ($this->savingsGoalMessageParser->looksLikeEditIntent($normalizedMessage)) {
            return $this->savingsGoalMessageParser->parseEdit($originalMessage, $fallbackName) !== null;
        }

        if (($state['last_action'] ?? null) !== 'query_savings') {
            return false;
        }

        if (! $this->containsAny($normalizedMessage, ['editar', 'edita', 'alterar', 'altera', 'ajustar', 'ajusta', 'mudar', 'muda', 'atualizar', 'atualiza'])) {
            return false;
        }

        return $fallbackName !== null
            && $this->savingsGoalMessageParser->parseEdit('meta '.($fallbackName ?? '').' '.$originalMessage, $fallbackName) !== null;
    }

    private function looksLikeSubscriptionEdit(string $originalMessage, string $normalizedMessage, array $state): bool
    {
        $fallbackName = $this->recentEntityName($state, 'subscriptions', 'subscription_name');

        if ($this->subscriptionMessageParser->looksLikeEditIntent($normalizedMessage)) {
            return $this->subscriptionMessageParser->parseEdit($originalMessage, $fallbackName) !== null;
        }

        if (($state['last_action'] ?? null) !== 'query_subscriptions') {
            return false;
        }

        if (! $this->containsAny($normalizedMessage, ['editar', 'edita', 'alterar', 'altera', 'ajustar', 'ajusta', 'mudar', 'muda', 'atualizar', 'atualiza'])) {
            return false;
        }

        return $this->subscriptionMessageParser->parseEdit('assinatura '.($fallbackName ?? '').' '.$originalMessage, $fallbackName) !== null;
    }

    private function looksLikeSubscriptionCancel(string $originalMessage, string $normalizedMessage, array $state): bool
    {
        $fallbackName = $this->recentEntityName($state, 'subscriptions', 'subscription_name');

        if ($this->subscriptionMessageParser->looksLikeCancelIntent($normalizedMessage)) {
            return $this->subscriptionMessageParser->parseCancel($originalMessage, $fallbackName) !== null;
        }

        if (($state['last_action'] ?? null) !== 'query_subscriptions') {
            return false;
        }

        if (! $this->containsAny($normalizedMessage, ['cancelar', 'cancela', 'desativar', 'desativa', 'pausar', 'pausa'])) {
            return false;
        }

        return $fallbackName !== null;
    }

    private function looksLikeBudgetQuery(string $message, array $state): bool
    {
        if ((str_contains($message, 'orcamento') || str_contains($message, 'orÃƒÂ§amento')) && $this->containsBudgetQueryCue($message)) {
            return true;
        }

        if (($state['last_action'] ?? null) !== 'query_budgets') {
            return false;
        }

        if ($this->containsAny($message, ['mes passado', 'mÃƒÂªs passado', 'esse mes', 'esse mÃƒÂªs', 'este mes', 'este mÃƒÂªs', 'ano passado'])) {
            return true;
        }

        if ($this->containsAny($message, ['mais apertad', 'mais folga', 'mais livre', 'compar', 'vs', 'versus'])) {
            return true;
        }

        return $this->looksLikeBudgetCategoryFollowUp($message);
    }

    private function looksLikeSavingsQuery(string $message, array $state): bool
    {
        if ($this->containsAny($message, ['meta', 'metas', 'objetivo', 'objetivos', 'poupanca', 'poupanÃƒÂ§a'])) {
            return ! $this->containsAny($message, ['criar', 'crie', 'nova', 'novo', 'definir', 'defina', 'cadastrar', 'cadastre']);
        }

        if (($state['last_action'] ?? null) !== 'query_savings') {
            return false;
        }

        if ($this->containsAny($message, ['assinatura', 'assinaturas', 'mensalidade', 'mensalidades', 'projecao', 'projeÃƒÂ§ÃƒÂ£o', 'projecoes', 'projeÃƒÂ§ÃƒÂµes', 'orcamento', 'orÃƒÂ§amento'])) {
            return false;
        }

        if (preg_match('/\b(quais|qual|me mostra|mostrar|listar|liste|lista|quero ver)\b/u', $message)) {
            return true;
        }

        return $this->looksLikeNamedFollowUp($message);
    }

    private function looksLikeSubscriptionQuery(string $message, array $state): bool
    {
        if ($this->containsAny($message, [
            'cancelar', 'cancela', 'desativar', 'desativa', 'pausar', 'pausa',
            'editar', 'edita', 'alterar', 'altera', 'ajustar', 'ajusta', 'mudar', 'muda', 'atualizar', 'atualiza',
        ])) {
            return false;
        }

        if ($this->containsAny($message, ['assinatura', 'assinaturas', 'mensalidade', 'mensalidades'])) {
            return ! $this->containsAny($message, ['criar', 'crie', 'nova', 'novo', 'definir', 'defina', 'cadastrar', 'cadastre']);
        }

        if (($state['last_action'] ?? null) !== 'query_subscriptions') {
            return false;
        }

        if ($this->containsAny($message, ['ativas', 'ativa', 'canceladas', 'cancelados', 'cancelada', 'inativas', 'inativa', 'impacto'])) {
            return true;
        }

        if ($this->containsAny($message, ['projecao', 'projeção', 'projecoes', 'projeções', 'daqui a', 'proximo mes', 'próximo mês', 'saldo futuro'])) {
            return false;
        }

        if (preg_match('/\b(quais|qual|me mostra|mostrar|listar|liste|lista|quero ver)\b/u', $message)) {
            return true;
        }

        return $this->looksLikeNamedFollowUp($message) || $this->containsAny($message, ['vence', 'vencem', 'proxima', 'proximo vencimento']);
    }

    private function looksLikeProjectionQuery(string $message, array $state): bool
    {
        if ($this->containsAny($message, ['projecao', 'projeÃƒÂ§ÃƒÂ£o', 'projecoes', 'projeÃƒÂ§ÃƒÂµes', 'futuro', 'daqui a', 'proximo mes', 'prÃƒÂ³ximo mÃƒÂªs'])) {
            return true;
        }

        if (($state['last_action'] ?? null) !== 'query_projections') {
            return false;
        }

        return $this->containsAny($message, ['daqui a', 'proximo', 'prÃƒÂ³ximo', 'depois', 'seguinte']);
    }

    private function looksLikeTransactionFollowUp(string $message, array $state): bool
    {
        $lastAction = $state['last_action'] ?? null;

        if (! in_array($lastAction, ['query_transactions', 'query_category'], true)) {
            return false;
        }

        if ($this->containsAny($message, ['mes passado', 'mÃƒÂªs passado', 'esse mes', 'esse mÃƒÂªs', 'este mes', 'este mÃƒÂªs', 'hoje', 'ontem'])) {
            return true;
        }

        if ($this->containsAny($message, ['compare', 'comparar', 'versus', 'vs', 'mais pesou', 'mais pesa'])) {
            return true;
        }

        return $this->looksLikeCategoryFollowUpMessage($message);
    }

    private function containsBudgetQueryCue(string $message): bool
    {
        return $this->containsAny($message, ['qual', 'quais', 'mostrar', 'mostra', 'meu', 'meus', 'listar', 'liste', 'compare', 'comparar']);
    }

    private function containsAny(string $message, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($message, $this->normalize($needle))) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeBudgetCategoryFollowUp(string $message): bool
    {
        if (! preg_match('/^(?:e\s+)?(?:o|a|os|as)?\s*([\p{L}\p{N} _-]+)$/u', $message, $matches)) {
            return false;
        }

        $term = trim($matches[1] ?? '');
        if ($term === '') {
            return false;
        }

        $term = preg_replace('/\b(orcamento|orÃƒÂ§amento|mes|mÃƒÂªs|geral|gerais|tambem|tambÃƒÂ©m)\b/u', '', $term) ?? $term;
        $term = trim($term);
        if ($term === '') {
            return false;
        }

        $wordCount = count(array_filter(explode(' ', $term)));

        return $wordCount <= 3 && ! $this->containsAny($term, ['saldo', 'gasto', 'receita', 'relatorio', 'relatÃƒÂ³rio']);
    }

    private function looksLikeCategoryFollowUpMessage(string $message): bool
    {
        if (! preg_match('/^(?:e\s+)?(?:o|a|os|as)?\s*([\p{L}\p{N} _-]+)$/u', $message, $matches)) {
            return false;
        }

        $term = trim($matches[1] ?? '');
        if ($term === '') {
            return false;
        }

        $wordCount = count(array_filter(explode(' ', $term)));

        return $wordCount <= 3 && ! $this->containsAny($term, ['saldo', 'orcamento', 'orÃƒÂ§amento', 'relatorio', 'relatÃƒÂ³rio']);
    }

    private function looksLikeNamedFollowUp(string $message): bool
    {
        if (! preg_match('/^(?:e\s+)?(?:o|a|os|as)?\s*([\p{L}\p{N} _-]+)$/u', $message, $matches)) {
            return false;
        }

        $term = trim($matches[1] ?? '');

        return $term !== '' && count(array_filter(explode(' ', $term))) <= 4;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $converted !== false ? $converted : $value;
    }

    private function recentEntityName(array $state, string $topic, string $field): ?string
    {
        if (($state['last_entities']['topic'] ?? null) === $topic && ! empty($state['last_entities'][$field])) {
            return (string) $state['last_entities'][$field];
        }

        foreach (($state['recent_contexts'] ?? []) as $context) {
            if (($context['entities']['topic'] ?? null) === $topic && ! empty($context['entities'][$field])) {
                return (string) $context['entities'][$field];
            }
        }

        return null;
    }
}
