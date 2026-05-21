<?php

namespace App\Services\WhatsApp;

class MessageClassifier
{
    public function __construct(
        private readonly SavingsGoalMessageParser $savingsGoalMessageParser
    ) {}

    public function classify(string $message, array $state = []): array
    {
        $normalized = $this->normalize($message);
        $stripped = preg_replace('/[!?.]+/u', '', $normalized) ?? $normalized;

        if ($this->isGreeting($stripped)) {
            return ['kind' => 'greeting', 'normalized' => $stripped];
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

        if ($this->looksLikeSavingsCreate($message, $stripped)) {
            return ['kind' => 'savings_create', 'normalized' => $stripped];
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
        if (in_array($message, ['cancelar', 'cancela', 'deixa', 'deixa pra la', 'nao', 'não'], true)) {
            return true;
        }

        foreach (['nao quero', 'não quero', 'agora nao', 'agora não', 'nao precisa', 'não precisa'] as $phrase) {
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

    private function looksLikeBudgetQuery(string $message, array $state): bool
    {
        if ((str_contains($message, 'orcamento') || str_contains($message, 'orçamento')) && $this->containsBudgetQueryCue($message)) {
            return true;
        }

        if (($state['last_action'] ?? null) !== 'query_budgets') {
            return false;
        }

        if ($this->containsAny($message, ['mes passado', 'mês passado', 'esse mes', 'esse mês', 'este mes', 'este mês', 'ano passado'])) {
            return true;
        }

        if ($this->containsAny($message, ['mais apertad', 'mais folga', 'mais livre', 'compar', 'vs', 'versus'])) {
            return true;
        }

        return $this->looksLikeBudgetCategoryFollowUp($message);
    }

    private function looksLikeSavingsQuery(string $message, array $state): bool
    {
        if ($this->containsAny($message, ['meta', 'metas', 'objetivo', 'objetivos', 'poupanca', 'poupança'])) {
            return ! $this->containsAny($message, ['criar', 'crie', 'nova', 'novo', 'definir', 'defina', 'cadastrar', 'cadastre']);
        }

        if (($state['last_action'] ?? null) !== 'query_savings') {
            return false;
        }

        return $this->looksLikeNamedFollowUp($message);
    }

    private function looksLikeSubscriptionQuery(string $message, array $state): bool
    {
        if ($this->containsAny($message, ['assinatura', 'assinaturas', 'mensalidade', 'mensalidades'])) {
            return true;
        }

        if (($state['last_action'] ?? null) !== 'query_subscriptions') {
            return false;
        }

        return $this->looksLikeNamedFollowUp($message) || $this->containsAny($message, ['vence', 'vencem', 'proxima', 'proximo vencimento']);
    }

    private function looksLikeProjectionQuery(string $message, array $state): bool
    {
        if ($this->containsAny($message, ['projecao', 'projeção', 'projecoes', 'projeções', 'futuro', 'daqui a', 'proximo mes', 'próximo mês'])) {
            return true;
        }

        if (($state['last_action'] ?? null) !== 'query_projections') {
            return false;
        }

        return $this->containsAny($message, ['daqui a', 'proximo', 'próximo', 'depois', 'seguinte']);
    }

    private function looksLikeTransactionFollowUp(string $message, array $state): bool
    {
        $lastAction = $state['last_action'] ?? null;

        if (! in_array($lastAction, ['query_transactions', 'query_category'], true)) {
            return false;
        }

        if ($this->containsAny($message, ['mes passado', 'mês passado', 'esse mes', 'esse mês', 'este mes', 'este mês', 'hoje', 'ontem'])) {
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

        $term = preg_replace('/\b(orcamento|orçamento|mes|mês|geral|gerais|tambem|também)\b/u', '', $term) ?? $term;
        $term = trim($term);
        if ($term === '') {
            return false;
        }

        $wordCount = count(array_filter(explode(' ', $term)));

        return $wordCount <= 3 && ! $this->containsAny($term, ['saldo', 'gasto', 'receita', 'relatorio', 'relatório']);
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

        return $wordCount <= 3 && ! $this->containsAny($term, ['saldo', 'orcamento', 'orçamento', 'relatorio', 'relatório']);
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
}
