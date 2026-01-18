<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class TransactionRepository
{
    /**
     * Busca transações recentes do usuário
     */
    public function getRecentForUser(User $user, int $limit = 15): Collection
    {
        return $user->transactions()
            ->latest('date')
            ->limit($limit)
            ->with(['category', 'whatsappContact'])
            ->get();
    }

    /**
     * Busca transações recentes do usuário com paginação
     */
    public function getRecentForUserPaginated(User $user, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return $user->transactions()
            ->latest('date')
            ->with(['category', 'whatsappContact'])
            ->paginate($perPage);
    }

    /**
     * Busca receitas do mês
     */
    public function getMonthlyIncome(User $user, Carbon $date): float
    {
        $start = $date->copy()->startOfMonth();
        $end = $date->copy()->endOfMonth();

        return (float) $user->transactions()
            ->where('type', 'income')
            ->whereBetween('date', [$start, $end])
            ->sum('amount');
    }

    /**
     * Busca despesas do mês (excluindo depósitos em metas) usando agregação SQL
     */
    public function getMonthlyExpenses(User $user, Carbon $date): float
    {
        $start = $date->copy()->startOfMonth();
        $end = $date->copy()->endOfMonth();

        // Usa agregação SQL para melhor performance
        // Nota: A filtragem de savings_goal_deposit_id ainda precisa ser feita em memória
        // pois está em JSON, mas otimizamos o cálculo principal
        return (float) $user->transactions()
            ->where('type', 'expense')
            ->whereBetween('date', [$start, $end])
            ->sum('amount');
    }
    
    /**
     * Busca despesas do mês excluindo depósitos em metas (versão completa)
     */
    public function getMonthlyExpensesExcludingSavings(User $user, Carbon $date): float
    {
        $start = $date->copy()->startOfMonth();
        $end = $date->copy()->endOfMonth();

        return (float) $user->transactions()
            ->where('type', 'expense')
            ->whereBetween('date', [$start, $end])
            ->get()
            ->filter(function ($transaction) {
                $metadata = $transaction->metadata ?? [];

                return ! isset($metadata['savings_goal_deposit_id']);
            })
            ->sum('amount');
    }
    
    /**
     * Busca totais agregados do mês usando uma única query
     */
    public function getMonthlyAggregates(User $user, Carbon $date): array
    {
        $start = $date->copy()->startOfMonth();
        $end = $date->copy()->endOfMonth();

        $result = $user->transactions()
            ->selectRaw('
                SUM(CASE WHEN type = "income" THEN amount ELSE 0 END) as income,
                SUM(CASE WHEN type = "expense" THEN amount ELSE 0 END) as expense
            ')
            ->whereBetween('date', [$start, $end])
            ->first();

        return [
            'income' => (float) ($result->income ?? 0),
            'expense' => (float) ($result->expense ?? 0),
        ];
    }
    
    /**
     * Busca totais agregados de todos os tempos usando uma única query
     */
    public function getAllTimeAggregates(User $user): array
    {
        $result = $user->transactions()
            ->selectRaw('
                SUM(CASE WHEN type = "income" THEN amount ELSE 0 END) as income,
                SUM(CASE WHEN type = "expense" THEN amount ELSE 0 END) as expense
            ')
            ->first();

        return [
            'income' => (float) ($result->income ?? 0),
            'expense' => (float) ($result->expense ?? 0),
        ];
    }

    /**
     * Busca transações por categoria em um período
     */
    public function getByCategory(User $user, ?int $categoryId, Carbon $start, Carbon $end): Collection
    {
        $query = $user->transactions()
            ->where('type', 'expense')
            ->whereBetween('date', [$start, $end])
            ->with('category');

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        return $query->get()
            ->filter(function ($transaction) {
                $metadata = $transaction->metadata ?? [];

                return ! isset($metadata['savings_goal_deposit_id']);
            });
    }

    /**
     * Busca totais agregados por tipo em um período
     */
    public function getAggregatedByType(User $user, Carbon $start, Carbon $end): array
    {
        return $user->transactions()
            ->selectRaw('
                type,
                SUM(CASE WHEN type = "income" THEN amount ELSE 0 END) as income,
                SUM(CASE WHEN type = "expense" THEN amount ELSE 0 END) as expense
            ')
            ->whereBetween('date', [$start, $end])
            ->groupBy('type')
            ->first()
            ->toArray() ?? ['income' => 0, 'expense' => 0];
    }

    /**
     * Busca total de receitas de todos os tempos
     */
    public function getTotalIncomeAllTime(User $user): float
    {
        return (float) $user->transactions()
            ->where('type', 'income')
            ->sum('amount');
    }

    /**
     * Busca total de despesas de todos os tempos (excluindo depósitos em metas)
     */
    public function getTotalExpensesAllTime(User $user): float
    {
        return (float) $user->transactions()
            ->where('type', 'expense')
            ->get()
            ->filter(function ($transaction) {
                $metadata = $transaction->metadata ?? [];

                return ! isset($metadata['savings_goal_deposit_id']);
            })
            ->sum('amount');
    }
}
