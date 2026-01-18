<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\TransactionDuplicate;
use App\Models\User;
use Illuminate\Support\Collection;

class TransactionDuplicateDetectionService
{
    public function detectDuplicates(User $user, ?int $days = 7): Collection
    {
        $transactions = $user->transactions()
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('date', 'desc')
            ->orderBy('amount', 'desc')
            ->get();

        $duplicates = collect();

        foreach ($transactions as $transaction) {
            $potentialDuplicates = $this->findPotentialDuplicates($transaction, $transactions);

            foreach ($potentialDuplicates as $duplicate) {
                $similarity = $this->calculateSimilarity($transaction, $duplicate);

                if ($similarity >= 80) {
                    $duplicates->push([
                        'transaction' => $transaction,
                        'duplicate' => $duplicate,
                        'similarity' => $similarity,
                    ]);

                    // Salvar no banco se ainda não existe
                    TransactionDuplicate::firstOrCreate([
                        'user_id' => $user->id,
                        'transaction_id' => $transaction->id,
                        'duplicate_transaction_id' => $duplicate->id,
                    ], [
                        'similarity_score' => $similarity,
                        'match_criteria' => $this->getMatchCriteria($transaction, $duplicate),
                    ]);
                }
            }
        }

        return $duplicates;
    }

    protected function findPotentialDuplicates(Transaction $transaction, Collection $transactions): Collection
    {
        return $transactions->filter(function ($t) use ($transaction) {
            return $t->id !== $transaction->id
                && $t->type === $transaction->type
                && abs($t->amount - $transaction->amount) < 0.01
                && $t->date->isSameDay($transaction->date);
        });
    }

    protected function calculateSimilarity(Transaction $t1, Transaction $t2): float
    {
        $score = 0;
        $factors = 0;

        // Valor (peso 40%)
        if (abs($t1->amount - $t2->amount) < 0.01) {
            $score += 40;
        }
        $factors += 40;

        // Data (peso 30%)
        if ($t1->date->isSameDay($t2->date)) {
            $score += 30;
        } elseif ($t1->date->diffInDays($t2->date) <= 1) {
            $score += 15;
        }
        $factors += 30;

        // Descrição (peso 20%)
        if ($t1->description && $t2->description) {
            $similarity = $this->stringSimilarity($t1->description, $t2->description);
            $score += $similarity * 20;
        }
        $factors += 20;

        // Categoria (peso 10%)
        if ($t1->category_id === $t2->category_id) {
            $score += 10;
        }
        $factors += 10;

        return ($score / $factors) * 100;
    }

    protected function stringSimilarity(string $str1, string $str2): float
    {
        $str1 = mb_strtolower($str1);
        $str2 = mb_strtolower($str2);

        if ($str1 === $str2) {
            return 1.0;
        }

        similar_text($str1, $str2, $percent);

        return $percent / 100;
    }

    protected function getMatchCriteria(Transaction $t1, Transaction $t2): array
    {
        return [
            'amount_match' => abs($t1->amount - $t2->amount) < 0.01,
            'date_match' => $t1->date->isSameDay($t2->date),
            'description_similarity' => $this->stringSimilarity($t1->description ?? '', $t2->description ?? ''),
            'category_match' => $t1->category_id === $t2->category_id,
        ];
    }

    public function resolveDuplicate(int $duplicateId, bool $keepFirst = true): void
    {
        $duplicate = TransactionDuplicate::findOrFail($duplicateId);

        if ($keepFirst) {
            $duplicate->duplicateTransaction->delete();
        } else {
            $duplicate->transaction->delete();
        }

        $duplicate->update(['is_resolved' => true]);
    }
}
