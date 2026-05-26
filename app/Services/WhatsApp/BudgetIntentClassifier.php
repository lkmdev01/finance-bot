<?php

namespace App\Services\WhatsApp;

use App\Services\WhatsApp\Support\NormalizesWhatsAppText;

class BudgetIntentClassifier
{
    use NormalizesWhatsAppText;

    public function __construct(
        private readonly BudgetMessageParser $budgetMessageParser,
        private readonly ConversationContextResolver $contextResolver,
    ) {}

    public function classify(string $originalMessage, string $normalizedMessage, array $state): ?array
    {
        if ($this->looksLikeBudgetCreate($originalMessage, $normalizedMessage)) {
            return ['kind' => 'budget_create', 'normalized' => $normalizedMessage];
        }

        if ($this->looksLikeBudgetDelete($originalMessage, $normalizedMessage, $state)) {
            return ['kind' => 'budget_delete', 'normalized' => $normalizedMessage];
        }

        if ($this->looksLikeBudgetEdit($originalMessage, $normalizedMessage, $state)) {
            return ['kind' => 'budget_edit', 'normalized' => $normalizedMessage];
        }

        if ($this->looksLikeBudgetQuery($normalizedMessage, $state)) {
            return ['kind' => 'budget_query', 'normalized' => $normalizedMessage];
        }

        return null;
    }

    private function looksLikeBudgetCreate(string $originalMessage, string $normalizedMessage): bool
    {
        if (! $this->containsBudgetCue($originalMessage, $normalizedMessage)) {
            return false;
        }

        return $this->budgetMessageParser->looksLikeCreateIntent($normalizedMessage)
            && $this->budgetMessageParser->parseCreate($originalMessage) !== null;
    }

    private function looksLikeBudgetEdit(string $originalMessage, string $normalizedMessage, array $state): bool
    {
        $fallbackCategory = $this->contextResolver->recentEntityName($state, 'budget', 'category_name');
        $fallbackPeriod = $this->contextResolver->recentBudgetPeriod($state);

        if ($this->containsBudgetCue($originalMessage, $normalizedMessage)
            && $this->containsAnyText($normalizedMessage, ['editar', 'edita', 'alterar', 'altera', 'ajustar', 'ajusta', 'mudar', 'muda', 'atualizar', 'atualiza'])) {
            return true;
        }

        if ($this->budgetMessageParser->looksLikeEditIntent($normalizedMessage)) {
            return $this->budgetMessageParser->parseEdit($originalMessage, $fallbackCategory, $fallbackPeriod) !== null;
        }

        if (($state['last_action'] ?? null) !== 'query_budgets') {
            return false;
        }

        if (! $this->containsAnyText($normalizedMessage, ['editar', 'edita', 'alterar', 'altera', 'ajustar', 'ajusta', 'mudar', 'muda', 'atualizar', 'atualiza'])) {
            return false;
        }

        if ($fallbackCategory !== null) {
            return $this->budgetMessageParser->parseEdit(
                'orcamento '.$fallbackCategory.' '.$originalMessage,
                $fallbackCategory,
                $fallbackPeriod
            ) !== null;
        }

        return true;
    }

    private function looksLikeBudgetDelete(string $originalMessage, string $normalizedMessage, array $state): bool
    {
        $fallbackCategory = $this->contextResolver->recentEntityName($state, 'budget', 'category_name');
        $fallbackPeriod = $this->contextResolver->recentBudgetPeriod($state);

        if ($this->containsBudgetCue($originalMessage, $normalizedMessage)
            && $this->containsAnyText($normalizedMessage, ['cancelar', 'cancela', 'apagar', 'apaga', 'remover', 'remove', 'excluir', 'exclui'])) {
            return true;
        }

        if ($this->budgetMessageParser->looksLikeDeleteIntent($normalizedMessage)) {
            return $this->budgetMessageParser->parseDelete($originalMessage, $fallbackCategory, $fallbackPeriod) !== null;
        }

        if (($state['last_action'] ?? null) !== 'query_budgets') {
            return false;
        }

        if (! $this->containsAnyText($normalizedMessage, ['cancelar', 'cancela', 'apagar', 'apaga', 'remover', 'remove', 'excluir', 'exclui'])) {
            return false;
        }

        if ($fallbackCategory !== null) {
            return $this->budgetMessageParser->parseDelete(
                'orcamento '.$fallbackCategory.' '.$originalMessage,
                $fallbackCategory,
                $fallbackPeriod
            ) !== null;
        }

        return true;
    }

    private function looksLikeBudgetQuery(string $message, array $state): bool
    {
        if ($this->containsBudgetCue($message, $message) && $this->containsBudgetQueryCue($message)) {
            return true;
        }

        if (($state['last_action'] ?? null) !== 'query_budgets') {
            return false;
        }

        if ($this->containsAnyText($message, ['mes passado', 'esse mes', 'este mes', 'ano passado'])) {
            return true;
        }

        if ($this->containsAnyText($message, ['mais apertad', 'mais folga', 'mais livre', 'compar', 'vs', 'versus'])) {
            return true;
        }

        return $this->looksLikeBudgetCategoryFollowUp($message);
    }

    private function containsBudgetQueryCue(string $message): bool
    {
        return $this->containsAnyText($message, ['qual', 'quais', 'mostrar', 'mostra', 'meu', 'meus', 'listar', 'liste', 'compare', 'comparar']);
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

        $term = preg_replace('/\b(orcamento|mes|geral|gerais|tambem)\b/u', '', $term) ?? $term;
        $term = trim($term);
        if ($term === '') {
            return false;
        }

        $wordCount = count(array_filter(explode(' ', $term)));

        return $wordCount <= 3 && ! $this->containsAnyText($term, ['saldo', 'gasto', 'receita', 'relatorio']);
    }

    private function containsBudgetCue(string $originalMessage, string $normalizedMessage): bool
    {
        return str_contains($normalizedMessage, 'orcamento')
            || str_contains($normalizedMessage, 'oramento')
            || preg_match('/or.{0,4}amento/iu', $originalMessage) === 1;
    }
}
