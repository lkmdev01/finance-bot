<?php

namespace App\Services\WhatsApp;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Collection;

class SubscriptionConversationService
{
    public function buildReply(User $user, string $message, array $state = []): array
    {
        $context = $this->buildContext($user, $message, $state);
        $subscriptions = $this->loadSubscriptions($user, $context);

        if ($subscriptions->isEmpty()) {
            return [
                'reply' => $this->buildEmptyReply($context),
                'entities' => $this->buildEntities($context),
            ];
        }

        if (! empty($context['subscription_names'])) {
            return [
                'reply' => $this->buildSubscriptionReply($subscriptions),
                'entities' => $this->buildEntities($context, [
                    'subscription_name' => $subscriptions->count() === 1 ? $subscriptions->first()->name : null,
                    'subscription_names' => $subscriptions->pluck('name')->values()->all(),
                ]),
            ];
        }

        return [
            'reply' => $this->buildSummaryReply($subscriptions, $context),
            'entities' => $this->buildEntities($context, [
                'subscription_count' => $subscriptions->count(),
            ]),
        ];
    }

    private function buildContext(User $user, string $message, array $state): array
    {
        $normalized = $this->normalize($message);
        $available = $this->buildIndex($user);
        $lastEntities = $state['last_entities'] ?? [];

        return [
            'normalized_message' => $normalized,
            'subscription_names' => $this->resolveSubscriptions($normalized, $available, $lastEntities),
            'due_filter' => $this->resolveDueFilter($normalized),
            'include_inactive' => $this->containsAny($normalized, ['inativa', 'inativas', 'cancelada', 'canceladas', 'todas']),
        ];
    }

    private function buildIndex(User $user): Collection
    {
        return $user->subscriptions()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Subscription $subscription) => [
                'name' => $subscription->name,
                'normalized' => $this->normalize($subscription->name),
            ]);
    }

    private function resolveSubscriptions(string $message, Collection $available, array $lastEntities): array
    {
        $matches = [];

        foreach ($available as $subscription) {
            if ($subscription['normalized'] !== '' && str_contains($message, $subscription['normalized'])) {
                $matches[] = $subscription['name'];
            }
        }

        $matches = array_values(array_unique($matches));

        if ($matches !== []) {
            return $matches;
        }

        if (($lastEntities['topic'] ?? null) === 'subscriptions' && ! empty($lastEntities['subscription_name']) && $this->looksLikeFollowUp($message)) {
            return [(string) $lastEntities['subscription_name']];
        }

        return [];
    }

    private function resolveDueFilter(string $message): ?string
    {
        if ($this->containsAny($message, ['vencidas', 'atrasadas', 'em atraso'])) {
            return 'overdue';
        }

        if ($this->containsAny($message, ['vence', 'vencem', 'proxima', 'proximo vencimento', 'esta semana'])) {
            return 'due_soon';
        }

        return null;
    }

    private function loadSubscriptions(User $user, array $context): Collection
    {
        $query = $user->subscriptions()->with(['category', 'bankAccount', 'creditCard'])->orderBy('next_due_date')->orderBy('name');

        if (! $context['include_inactive']) {
            $query->where('is_active', true);
        }

        $subscriptions = $query->get();

        if ($context['due_filter'] === 'overdue') {
            $subscriptions = $subscriptions->filter(fn (Subscription $subscription) => $subscription->is_active && $subscription->next_due_date && $subscription->next_due_date->lt(now()->startOfDay()))->values();
        } elseif ($context['due_filter'] === 'due_soon') {
            $subscriptions = $subscriptions->filter(fn (Subscription $subscription) => $subscription->is_active && $subscription->next_due_date && $subscription->next_due_date->lte(now()->addDays(7)->startOfDay()))->values();
        }

        if (! empty($context['subscription_names'])) {
            $targets = array_map(fn (string $name) => $this->normalize($name), $context['subscription_names']);
            $subscriptions = $subscriptions->filter(fn (Subscription $subscription) => in_array($this->normalize($subscription->name), $targets, true))->values();
        }

        return $subscriptions->values();
    }

    private function buildEmptyReply(array $context): string
    {
        if (! empty($context['subscription_names'])) {
            return 'Nao encontrei essa assinatura ativa. Se quiser, eu posso listar suas assinaturas ou olhar as proximas cobrancas.';
        }

        if ($context['due_filter'] === 'overdue') {
            return 'No momento, voce nao tem assinaturas vencidas.';
        }

        if ($context['due_filter'] === 'due_soon') {
            return 'No momento, nao encontrei assinaturas vencendo nos proximos dias.';
        }

        return 'Voce ainda nao tem assinaturas cadastradas para eu acompanhar.';
    }

    private function buildSummaryReply(Collection $subscriptions, array $context): string
    {
        $lines = $subscriptions->take(6)->map(fn (Subscription $subscription) => sprintf(
            '- %s: R$ %s | %s | proximo vencimento %s',
            $subscription->name,
            $this->formatMoney($subscription->amount),
            $subscription->billing_cycle === 'yearly' ? 'anual' : 'mensal',
            $subscription->next_due_date?->format('d/m/Y') ?? 'sem data'
        ))->implode("\n");

        $monthlyTotal = $subscriptions
            ->filter(fn (Subscription $subscription) => $subscription->is_active && $subscription->billing_cycle === 'monthly')
            ->sum('amount');

        $reply = "Suas assinaturas atuais:\n{$lines}\n\nTotal mensal ativo: R$ {$this->formatMoney($monthlyTotal)}.";
        $advisor = app(FinancialConversationAdvisor::class);

        if (($insight = $advisor->subscriptionSummaryInsight($subscriptions)) !== null) {
            $reply .= ' '.$insight;
        }

        $reply .= ' Se quiser, eu posso abrir uma assinatura especifica, te dizer o que vence primeiro ou comparar o peso delas no mes.';

        return $reply;
    }

    private function buildSubscriptionReply(Collection $subscriptions): string
    {
        if ($subscriptions->count() > 1) {
            $lines = $subscriptions->map(fn (Subscription $subscription) => sprintf(
                '- %s: R$ %s | vence em %s',
                $subscription->name,
                $this->formatMoney($subscription->amount),
                $subscription->next_due_date?->format('d/m/Y') ?? 'sem data'
            ))->implode("\n");

            return "Encontrei estas assinaturas:\n{$lines}\n\nSe quiser, eu posso detalhar qualquer uma delas.";
        }

        /** @var Subscription $subscription */
        $subscription = $subscriptions->first();
        $reply = sprintf(
            '%s custa R$ %s por ciclo %s. O proximo vencimento esta em %s e a origem usada hoje e %s.',
            $subscription->name,
            $this->formatMoney($subscription->amount),
            $subscription->billing_cycle === 'yearly' ? 'anual' : 'mensal',
            $subscription->next_due_date?->format('d/m/Y') ?? 'sem data definida',
            $subscription->source_label
        );

        if (($insight = app(FinancialConversationAdvisor::class)->subscriptionItemInsight($subscription)) !== null) {
            $reply .= ' '.$insight;
        }

        $reply .= ' Se quiser, eu posso comparar com outra assinatura ou te mostrar o que vence primeiro.';

        return $reply;
    }

    private function buildEntities(array $context, array $extra = []): array
    {
        return array_filter(array_merge([
            'topic' => 'subscriptions',
            'subscription_name' => count($context['subscription_names']) === 1 ? $context['subscription_names'][0] : null,
            'subscription_names' => count($context['subscription_names']) > 1 ? $context['subscription_names'] : null,
            'subscription_due_filter' => $context['due_filter'],
        ], $extra), fn ($value) => $value !== null && $value !== [] && $value !== '');
    }

    private function looksLikeFollowUp(string $message): bool
    {
        if (! preg_match('/^(?:e\s+)?(?:a|o|as|os)?\s*([\p{L}\p{N} _-]+)$/u', $message, $matches)) {
            return false;
        }

        $term = trim($matches[1] ?? '');

        return $term !== '' && count(array_filter(explode(' ', $term))) <= 4;
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $this->normalize($needle))) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $converted !== false ? $converted : $value;
    }

    private function formatMoney(float|string $value): string
    {
        return number_format((float) $value, 2, ',', '.');
    }
}
