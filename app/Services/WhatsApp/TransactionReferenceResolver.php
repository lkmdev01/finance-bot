<?php

namespace App\Services\WhatsApp;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;

class TransactionReferenceResolver
{
    public function resolve(User $user, array $payload, array $state = []): array
    {
        if (! empty($payload['transaction_id']) && is_numeric($payload['transaction_id'])) {
            $transaction = Transaction::query()
                ->where('user_id', $user->id)
                ->where('id', (int) $payload['transaction_id'])
                ->first();

            return [
                'transaction' => $transaction,
                'ambiguous' => false,
                'source' => 'id',
                'matches' => collect($transaction ? [$transaction] : []),
            ];
        }

        $query = Transaction::query()
            ->with('category')
            ->where('user_id', $user->id);

        $source = 'latest';

        if (! empty($payload['target_description'])) {
            $source = 'description';
            $query->where('description', 'like', '%'.$payload['target_description'].'%');
        } elseif (($payload['reference'] ?? null) === 'recent') {
            $source = 'recent';
            $recentId = $this->recentTransactionId($state);
            if ($recentId !== null) {
                $transaction = Transaction::query()
                    ->with('category')
                    ->where('user_id', $user->id)
                    ->where('id', $recentId)
                    ->first();

                return [
                    'transaction' => $transaction,
                    'ambiguous' => false,
                    'source' => 'recent',
                    'matches' => collect($transaction ? [$transaction] : []),
                ];
            }
        }

        if (($payload['target_date_scope'] ?? null) === 'yesterday') {
            $query->whereDate('date', now()->subDay()->toDateString());
        }

        if (($payload['target_date_scope'] ?? null) === 'today') {
            $query->whereDate('date', now()->toDateString());
        }

        if (! empty($payload['category_name'])) {
            $query->whereHas('category', function ($builder) use ($payload) {
                $builder->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $payload['category_name'])]);
            });
            $source = 'category';
        }

        $matches = $query->latest('date')->latest('id')->limit(5)->get();

        if ($matches->isEmpty() && ($payload['reference'] ?? null) === 'latest') {
            $matches = Transaction::query()
                ->with('category')
                ->where('user_id', $user->id)
                ->latest('date')
                ->latest('id')
                ->limit(5)
                ->get();
            $source = 'latest';
        }

        return [
            'transaction' => $matches->count() === 1 ? $matches->first() : ($source === 'latest' ? $matches->first() : null),
            'ambiguous' => $matches->count() > 1 && $source !== 'latest',
            'source' => $source,
            'matches' => $matches,
        ];
    }

    public function recentTransactionId(array $state): ?int
    {
        $lastEntities = $state['last_entities'] ?? [];

        if (! empty($lastEntities['transaction_id'])) {
            return (int) $lastEntities['transaction_id'];
        }

        if (! empty($lastEntities['latest_transaction_id'])) {
            return (int) $lastEntities['latest_transaction_id'];
        }

        foreach (($state['recent_contexts'] ?? []) as $context) {
            $entities = $context['entities'] ?? [];

            if (! empty($entities['transaction_id'])) {
                return (int) $entities['transaction_id'];
            }

            if (! empty($entities['latest_transaction_id'])) {
                return (int) $entities['latest_transaction_id'];
            }
        }

        return null;
    }

    public function formatTransactionLabel(Transaction $transaction): string
    {
        $label = $transaction->description ?: ($transaction->category?->name ?? 'transacao');

        return sprintf(
            '%s de R$ %s em %s',
            $label,
            number_format((float) $transaction->amount, 2, ',', '.'),
            $transaction->date?->format('d/m/Y') ?? now()->format('d/m/Y')
        );
    }

    public function formatAmbiguousOptions(Collection $matches): string
    {
        return $matches->take(3)->map(function (Transaction $transaction) {
            return '- '.$this->formatTransactionLabel($transaction);
        })->implode("\n");
    }
}
