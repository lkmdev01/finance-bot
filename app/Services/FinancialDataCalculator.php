<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class FinancialDataCalculator
{
    public function __construct(
        private readonly TransactionRepository $transactionRepository
    ) {}

    /**
     * Calcula dados financeiros do usuário para contexto da IA
     */
    public function calculate(User $user): array
    {
        $now = now();
        $startOfMonth = $now->copy()->startOfMonth();
        $endOfMonth = $now->copy()->endOfMonth();

        // Totais do mês atual - usando agregação SQL para melhor performance
        $monthlyAggregates = $this->transactionRepository->getMonthlyAggregates($user, $now);
        $monthlyIncome = $monthlyAggregates['income'] ?? 0;

        // Para despesas, ainda precisamos filtrar savings_goal_deposit_id em memória
        $monthlyExpensesRaw = $monthlyAggregates['expense'] ?? 0;
        $monthlyExpensesTransactions = $user->transactions()
            ->where('type', 'expense')
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->get()
            ->filter(function ($transaction) {
                $metadata = $transaction->metadata ?? [];

                return ! isset($metadata['savings_goal_deposit_id']);
            });

        // Calcula diferença entre total e sem savings para ajustar
        $savingsDepositsInMonth = $monthlyExpensesTransactions->sum('amount') - $monthlyExpensesRaw;
        $monthlyExpenses = $monthlyExpensesRaw - $savingsDepositsInMonth;

        // Totais de todos os tempos - usando agregação SQL
        $allTimeAggregates = $this->transactionRepository->getAllTimeAggregates($user);
        $totalIncomeAllTime = $allTimeAggregates['income'] ?? 0;

        // Para despesas totais, ainda precisamos filtrar savings em memória
        $allExpenses = $user->transactions()
            ->where('type', 'expense')
            ->get()
            ->filter(function ($transaction) {
                $metadata = $transaction->metadata ?? [];

                return ! isset($metadata['savings_goal_deposit_id']);
            })
            ->sum('amount');

        $totalExpensesAllTime = $allExpenses;

        // Calcula saldo disponível
        // Calcula total de depósitos em metas de poupança através dos SavingsGoals
        $totalSavingsDeposits = $user->savingsGoals()
            ->with('deposits')
            ->get()
            ->sum(fn ($goal) => $goal->deposits->sum('amount'));
        $availableBalance = $totalIncomeAllTime - $totalExpensesAllTime - $totalSavingsDeposits;

        // Despesas por categoria do mês atual
        $expensesByCategory = $user->transactions()
            ->where('type', 'expense')
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->with('category')
            ->get()
            ->filter(function ($transaction) {
                $metadata = $transaction->metadata ?? [];

                return ! isset($metadata['savings_goal_deposit_id']);
            })
            ->groupBy('category_id')
            ->map(function ($transactions, $categoryId) use ($monthlyExpenses) {
                $category = $transactions->first()->category;
                $total = $transactions->sum('amount');
                $percentage = $monthlyExpenses > 0 ? ($total / $monthlyExpenses) * 100 : 0;

                return [
                    'category_id' => $categoryId,
                    'category_name' => $category?->name ?? 'Sem categoria',
                    'total' => round($total, 2),
                    'count' => $transactions->count(),
                    'percentage' => round($percentage, 1),
                ];
            })
            ->sortByDesc('total')
            ->take(10)
            ->values()
            ->toArray();

        // Comparação com mês anterior
        $previousMonth = $now->copy()->subMonth();
        $previousMonthAggregates = $this->transactionRepository->getMonthlyAggregates($user, $previousMonth);
        $previousMonthIncome = $previousMonthAggregates['income'] ?? 0;
        $previousMonthExpenses = $previousMonthAggregates['expense'] ?? 0;

        $incomeVariation = $previousMonthIncome > 0
            ? (($monthlyIncome - $previousMonthIncome) / $previousMonthIncome) * 100
            : 0;

        $expensesVariation = $previousMonthExpenses > 0
            ? (($monthlyExpenses - $previousMonthExpenses) / $previousMonthExpenses) * 100
            : 0;

        // Evolução mensal (últimos 6 meses)
        $monthlyEvolution = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = $now->copy()->subMonths($i);
            $monthAggregates = $this->transactionRepository->getMonthlyAggregates($user, $month);
            $monthlyEvolution[] = [
                'month' => $month->format('M/Y'),
                'income' => round($monthAggregates['income'] ?? 0, 2),
                'expenses' => round($monthAggregates['expense'] ?? 0, 2),
                'balance' => round(($monthAggregates['income'] ?? 0) - ($monthAggregates['expense'] ?? 0), 2),
            ];
        }

        // Evolução anual (últimos 12 meses)
        $yearlyEvolution = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = $now->copy()->subMonths($i);
            $monthAggregates = $this->transactionRepository->getMonthlyAggregates($user, $month);
            $yearlyEvolution[] = [
                'month' => $month->format('M/Y'),
                'income' => round($monthAggregates['income'] ?? 0, 2),
                'expenses' => round($monthAggregates['expense'] ?? 0, 2),
                'balance' => round(($monthAggregates['income'] ?? 0) - ($monthAggregates['expense'] ?? 0), 2),
            ];
        }

        // Orçamentos excedidos
        $exceededBudgets = $user->budgets()
            ->with('category')
            ->get()
            ->filter(function ($budget) use ($startOfMonth, $endOfMonth) {
                $spent = $user->transactions()
                    ->where('category_id', $budget->category_id)
                    ->where('type', 'expense')
                    ->whereBetween('date', [$startOfMonth, $endOfMonth])
                    ->sum('amount');

                return $spent > $budget->amount;
            })
            ->map(function ($budget) use ($startOfMonth, $endOfMonth) {
                $spent = $user->transactions()
                    ->where('category_id', $budget->category_id)
                    ->where('type', 'expense')
                    ->whereBetween('date', [$startOfMonth, $endOfMonth])
                    ->sum('amount');

                return [
                    'category_name' => $budget->category->name,
                    'budget_amount' => round($budget->amount, 2),
                    'spent' => round($spent, 2),
                    'exceeded_by' => round($spent - $budget->amount, 2),
                    'percentage_used' => round(($spent / $budget->amount) * 100, 1),
                ];
            })
            ->values()
            ->toArray();

        // Metas de poupança detalhadas
        $savingsGoals = $user->savingsGoals()
            ->with('deposits')
            ->get()
            ->map(function ($goal) {
                $currentAmount = (float) $goal->deposits->sum('amount');
                $progress = $goal->target_amount > 0
                    ? min(100, ($currentAmount / $goal->target_amount) * 100)
                    : 0;

                return [
                    'name' => $goal->name,
                    'description' => $goal->description,
                    'target_amount' => round($goal->target_amount, 2),
                    'current_amount' => round($currentAmount, 2),
                    'remaining_amount' => round(max(0, $goal->target_amount - $currentAmount), 2),
                    'progress_percentage' => round($progress, 1),
                    'target_date' => $goal->target_date?->format('Y-m-d'),
                    'is_completed' => $goal->is_completed,
                ];
            })
            ->values()
            ->toArray();

        // Projeções financeiras (próximos 12 meses) - com cache (sem tags)
        $projections = Cache::remember("user.{$user->id}.financial_projections", 3600, function () use ($user) {
            return $user->financialProjections()
                ->orderBy('projection_date')
                ->limit(12)
                ->get()
                ->map(function ($projection) {
                    return [
                        'date' => $projection->projection_date->format('Y-m-d'),
                        'month' => $projection->projection_date->format('M/Y'),
                        'projected_balance' => round((float) $projection->projected_balance, 2),
                        'projected_income' => round((float) $projection->projected_income, 2),
                        'projected_expenses' => round((float) $projection->projected_expenses, 2),
                    ];
                })
                ->values()
                ->toArray();
        });

        return [
            'available_balance' => round($availableBalance, 2),
            'monthly_income' => round($monthlyIncome, 2),
            'monthly_expenses' => round($monthlyExpenses, 2),
            'monthly_balance' => round($monthlyIncome - $monthlyExpenses, 2),
            'total_income_all_time' => round($totalIncomeAllTime, 2),
            'total_expenses_all_time' => round($totalExpensesAllTime, 2),
            'total_savings' => round($totalSavingsDeposits, 2),
            'expenses_by_category_this_month' => $expensesByCategory,
            'current_month' => $now->format('F Y'),
            'current_date' => $now->format('Y-m-d'),
            'previous_month_income' => round($previousMonthIncome, 2),
            'previous_month_expenses' => round($previousMonthExpenses, 2),
            'income_variation_percentage' => round($incomeVariation, 1),
            'expenses_variation_percentage' => round($expensesVariation, 1),
            'monthly_evolution' => $monthlyEvolution,
            'yearly_evolution' => $yearlyEvolution,
            'exceeded_budgets' => $exceededBudgets,
            'savings_goals' => $savingsGoals,
            'financial_projections' => $projections,
        ];
    }
}
