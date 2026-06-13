<?php

namespace App\Services\WhatsApp;

use App\Models\RecurringTransaction;
use App\Models\User;
use Illuminate\Support\Collection;

class RecurringConversationService
{
    public function buildReply(User $user, string $message, array $state = []): array
    {
        $normalized = app(IncomingMessageNormalizer::class)->normalize($message);

        if (($followUpReply = $this->buildFollowUpReply($user, $normalized, $state)) !== null) {
            return $followUpReply;
        }

        $context = $this->buildContext($user, $message, $state);
        $recurrings = $this->loadRecurrings($user, $context);

        if ($recurrings->isEmpty()) {
            return [
                'reply' => ! empty($context['descriptions'])
                    ? 'Nao encontrei essa recorrencia ativa. Se quiser, eu posso listar as recorrencias atuais.'
                    : 'Voce ainda nao tem recorrencias ativas. Se quiser, eu posso criar uma para voce.',
                'entities' => ['topic' => 'recurring_transactions'],
            ];
        }

        if (! empty($context['descriptions'])) {
            return [
                'reply' => $this->buildRecurringReply($recurrings),
                'entities' => $this->buildEntities($context, [
                    'recurring_description' => $recurrings->count() === 1 ? $recurrings->first()->description : null,
                    'recurring_descriptions' => $recurrings->pluck('description')->values()->all(),
                ]),
            ];
        }

        return [
            'reply' => $this->buildSummaryReply($recurrings),
            'entities' => $this->buildEntities($context, [
                'recurring_count' => $recurrings->count(),
                'recent_recurring_ids' => $recurrings->pluck('id')->values()->all(),
                'recurring_description' => $recurrings->first()?->description,
            ]),
        ];
    }

    private function buildContext(User $user, string $message, array $state): array
    {
        $normalizer = app(IncomingMessageNormalizer::class);
        $normalized = $normalizer->normalize($message);
        $available = $user->recurringTransactions()
            ->orderBy('description')
            ->get(['id', 'description'])
            ->map(fn (RecurringTransaction $recurring) => [
                'description' => $recurring->description,
                'normalized' => $normalizer->normalize($recurring->description),
            ]);

        $matches = [];
        foreach ($available as $recurring) {
            if ($recurring['normalized'] !== '' && str_contains($normalized, $recurring['normalized'])) {
                $matches[] = $recurring['description'];
            }
        }

        if ($matches === [] && ($state['last_entities']['topic'] ?? null) === 'recurring_transactions') {
            $current = $state['last_entities']['recurring_description'] ?? null;
            if (is_string($current) && $current !== '' && $this->looksLikeFollowUp($normalized)) {
                $matches[] = $current;
            }
        }

        return [
            'descriptions' => array_values(array_unique($matches)),
        ];
    }

    private function loadRecurrings(User $user, array $context): Collection
    {
        $normalizer = app(IncomingMessageNormalizer::class);
        $query = $user->recurringTransactions()
            ->with(['category', 'bankAccount', 'creditCard'])
            ->where('is_active', true)
            ->orderBy('day_of_month')
            ->orderBy('description');

        $recurrings = $query->get();

        if (! empty($context['descriptions'])) {
            $targets = array_map(fn (string $value) => $normalizer->normalize($value), $context['descriptions']);
            $recurrings = $recurrings
                ->filter(fn (RecurringTransaction $recurring) => in_array($normalizer->normalize($recurring->description), $targets, true))
                ->values();
        }

        return $recurrings->values();
    }

    private function buildSummaryReply(Collection $recurrings): string
    {
        $lines = $recurrings->take(6)->map(function (RecurringTransaction $recurring) {
            $frequency = $recurring->frequency === 'weekly' ? 'semanal' : 'mensal';
            $schedule = $recurring->frequency === 'weekly'
                ? $frequency
                : $frequency.' no dia '.((int) ($recurring->day_of_month ?? 0));

            return sprintf(
                '- %s: R$ %s | %s',
                $recurring->description,
                $this->formatMoney($recurring->amount),
                trim($schedule)
            );
        })->implode("\n");

        return "Suas recorrencias ativas:\n{$lines}\n\nSe quiser, eu posso abrir uma recorrencia especifica, editar ou cancelar uma delas.";
    }

    private function buildRecurringReply(Collection $recurrings): string
    {
        if ($recurrings->count() > 1) {
            $lines = $recurrings->map(fn (RecurringTransaction $recurring) => sprintf(
                '- %s: R$ %s | %s',
                $recurring->description,
                $this->formatMoney($recurring->amount),
                $recurring->frequency === 'weekly' ? 'semanal' : 'mensal'
            ))->implode("\n");

            return "Encontrei estas recorrencias:\n{$lines}";
        }

        /** @var RecurringTransaction $recurring */
        $recurring = $recurrings->first();
        $schedule = $recurring->frequency === 'weekly'
            ? 'semanal'
            : 'mensal no dia '.((int) ($recurring->day_of_month ?? 0));

        return sprintf(
            '%s esta configurada em R$ %s, com frequencia %s. A origem usada hoje e %s.',
            $recurring->description,
            $this->formatMoney($recurring->amount),
            $schedule,
            $recurring->creditCard?->name ? 'cartao '.$recurring->creditCard->name : ($recurring->bankAccount?->name ? 'conta '.$recurring->bankAccount->name : 'sem origem definida')
        );
    }

    private function buildFollowUpReply(User $user, string $normalizedMessage, array $state): ?array
    {
        if (($state['last_entities']['topic'] ?? null) !== 'recurring_transactions') {
            return null;
        }

        $recurring = $this->resolveRecentRecurring($user, $state);
        $count = (int) ($state['last_entities']['recurring_count'] ?? 0);

        if ($this->containsAny($normalizedMessage, ['me mostra essa recorrencia', 'me mostra ela', 'mostra essa recorrencia', 'abre essa recorrencia'])) {
            if (! $recurring) {
                return null;
            }

            return [
                'reply' => $this->buildRecurringReply(collect([$recurring])),
                'entities' => [
                    'topic' => 'recurring_transactions',
                    'recurring_transaction_id' => $recurring->id,
                    'recurring_description' => $recurring->description,
                    'recent_recurring_ids' => $state['last_entities']['recent_recurring_ids'] ?? [],
                    'recurring_count' => max(1, $count),
                ],
            ];
        }

        if ($this->containsAny($normalizedMessage, ['tem mais recorrencia', 'tem mais recorrencias'])) {
            return [
                'reply' => $count > 1
                    ? "Sim. Eu encontrei {$count} recorrencias ativas nessa lista."
                    : 'Por enquanto, nao. So encontrei essa recorrencia nessa lista.',
                'entities' => [
                    'topic' => 'recurring_transactions',
                    'recurring_transaction_id' => $recurring?->id,
                    'recurring_description' => $recurring?->description,
                    'recent_recurring_ids' => $state['last_entities']['recent_recurring_ids'] ?? [],
                    'recurring_count' => max(1, $count),
                ],
            ];
        }

        return null;
    }

    private function resolveRecentRecurring(User $user, array $state): ?RecurringTransaction
    {
        $id = (int) ($state['last_entities']['recurring_transaction_id'] ?? 0);
        if ($id > 0) {
            return $user->recurringTransactions()->find($id);
        }

        $recentIds = array_values(array_filter($state['last_entities']['recent_recurring_ids'] ?? [], fn ($id) => (int) $id > 0));
        if ($recentIds === []) {
            return null;
        }

        return $user->recurringTransactions()->find((int) $recentIds[0]);
    }

    private function buildEntities(array $context, array $extra = []): array
    {
        return array_filter(array_merge([
            'topic' => 'recurring_transactions',
            'recurring_description' => count($context['descriptions']) === 1 ? $context['descriptions'][0] : null,
            'recurring_descriptions' => count($context['descriptions']) > 1 ? $context['descriptions'] : null,
        ], $extra), fn ($value) => $value !== null && $value !== [] && $value !== '');
    }

    private function looksLikeFollowUp(string $message): bool
    {
        if (! preg_match('/^(?:e\s+)?(?:a|o|as|os|da|do|de)?\s*([\p{L}\p{N} _-]+)$/u', $message, $matches)) {
            return false;
        }

        $term = trim($matches[1] ?? '');

        return $term !== '' && count(array_filter(explode(' ', $term))) <= 4;
    }

    private function containsAny(string $message, array $needles): bool
    {
        $normalizer = app(IncomingMessageNormalizer::class);

        foreach ($needles as $needle) {
            if (str_contains($message, $normalizer->normalize($needle))) {
                return true;
            }
        }

        return false;
    }

    private function formatMoney(float|string $value): string
    {
        return number_format((float) $value, 2, ',', '.');
    }
}
