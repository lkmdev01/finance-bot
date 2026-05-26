<?php

namespace App\Services\WhatsApp;

use App\Services\WhatsApp\Support\NormalizesWhatsAppText;

class DomainGate
{
    use NormalizesWhatsAppText;

    public function detect(string $message, array $state = []): string
    {
        $normalized = $this->normalizeText($message);

        if ($this->looksLikeReminderDomain($normalized)) {
            return 'reminder';
        }

        if ($this->looksLikeTransactionDomain($normalized, $state)) {
            return 'transaction';
        }

        if ($this->looksLikePlanningDomain($normalized, $state)) {
            return 'planning';
        }

        if ($this->looksLikeBudgetDomain($normalized, $state)) {
            return 'budget';
        }

        return 'general';
    }

    private function looksLikeReminderDomain(string $message): bool
    {
        if ($this->containsAnyText($message, ['me lembra', 'me lembre', 'lembra de', 'lembrete', 'lembrar de'])) {
            return true;
        }

        $hasScheduleCue = $this->containsAnyText($message, ['todo mes', 'cada mes', 'todo dia', 'cada ano']);
        if (! $hasScheduleCue) {
            return false;
        }

        // If the user provided a monetary value (even without "R$"/"reais"), this is usually a
        // recurring transaction/subscription intent, not a generic reminder.
        $hasMoneyWord = $this->containsAnyText($message, ['r$', 'reais', 'real', 'valor', 'ganho', 'ganhei', 'recebo', 'recebi']);

        // Detect a value that looks like a monetary amount.
        // Avoid treating "dia 10" (schedule) as an amount.
        $hasMoneyNumber = preg_match('/(?:^|\\s)(?:r\\$\\s*)?(?<!\\bdia\\s)\\d{2,}(?:[\\.,]\\d{1,2})?(?:\\s|$)/iu', $message) === 1;
        if (! $hasMoneyNumber && preg_match_all('/\\b\\d+(?:[\\.,]\\d{1,2})?\\b/u', $message, $matches) && count($matches[0] ?? []) >= 2) {
            $nums = array_map(fn ($n) => (float) str_replace(',', '.', str_replace('.', '', $n)), $matches[0]);
            // If there is any number greater than 31, it's almost certainly a monetary amount.
            $hasMoneyNumber = collect($nums)->max() > 31;
        }

        return ! $hasMoneyWord && ! $hasMoneyNumber;
    }

    private function looksLikeTransactionDomain(string $message, array $state): bool
    {
        // Budget messages should never be forced into "transaction" domain by context.
        if (str_contains($message, 'orcamento') || str_contains($message, 'oramento') || preg_match('/or.{0,4}amento/iu', $message) === 1) {
            return false;
        }

        if (($state['last_entities']['topic'] ?? null) === 'recurring_transactions') {
            return true;
        }

        // Quando o usuario esta falando sobre transacoes recentes e usa referencias curtas,
        // priorizar o dominio de transacoes (mesmo que mencione "cartao", "meta", etc).
        if (in_array($state['last_action'] ?? null, ['query_transactions', 'query_category'], true)) {
            if ($this->containsAnyText($message, [
                'ajusta', 'ajustar', 'edita', 'editar', 'muda', 'mudar', 'altera', 'alterar', 'corrige', 'corrigir',
                'apaga', 'apagar', 'remove', 'remover', 'deleta', 'deletar', 'exclui', 'excluir',
                'esse', 'essa', 'aquele', 'aquela', 'ultimo', 'ultima', 'penultimo', 'penultima', 'ontem', 'hoje',
            ])) {
                return true;
            }
        }

        return $this->containsAnyText($message, [
            'gastei', 'paguei', 'recebi', 'ganhei', 'entrou', 'comprei', 'parcelei',
            'todo mes pagar', 'todo dia pagar', 'academia', 'debito', 'credito', 'pix',
        ]);
    }

    private function looksLikePlanningDomain(string $message, array $state): bool
    {
        if (($state['last_entities']['topic'] ?? null) === 'subscriptions') {
            return true;
        }

        // Budget messages should stay in the budget domain, not planning.
        if ($this->containsAnyText($message, ['orcamento', 'oramento']) || preg_match('/or.{0,4}amento/iu', $message) === 1) {
            return false;
        }

        return $this->containsAnyText($message, [
            'meta', 'metas', 'objetivo', 'assinatura', 'assinaturas',
            'projecao', 'projecoes', 'cartao', 'cartoes', 'credito',
        ]);
    }

    private function looksLikeBudgetDomain(string $message, array $state): bool
    {
        return $this->containsAnyText($message, ['orcamento', 'oramento'])
            || ($state['last_action'] ?? null) === 'query_budgets';
    }
}
