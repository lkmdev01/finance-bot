<?php

namespace App\Services;

use App\Models\FinancialProjection;
use App\Models\RecurringTransaction;
use App\Models\User;
use Carbon\Carbon;

class FinancialProjectionService
{
    public function generateProjections(User $user, int $months = 12): array
    {
        $projections = [];
        $currentBalance = $this->getCurrentBalance($user);
        $currentDate = now();

        for ($i = 1; $i <= $months; $i++) {
            $projectionDate = $currentDate->copy()->addMonths($i);
            $projectedIncome = $this->calculateProjectedIncome($user, $projectionDate);
            $projectedExpenses = $this->calculateProjectedExpenses($user, $projectionDate);
            $projectedBalance = $currentBalance + $projectedIncome - $projectedExpenses;

            $projections[] = [
                'date' => $projectionDate->format('Y-m-d'),
                'projected_balance' => $projectedBalance,
                'projected_income' => $projectedIncome,
                'projected_expenses' => $projectedExpenses,
            ];

            // Salvar no banco
            FinancialProjection::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'projection_date' => $projectionDate,
                ],
                [
                    'projected_balance' => $projectedBalance,
                    'projected_income' => $projectedIncome,
                    'projected_expenses' => $projectedExpenses,
                    'assumptions' => [
                        'average_monthly_income' => $this->getAverageMonthlyIncome($user),
                        'average_monthly_expenses' => $this->getAverageMonthlyExpenses($user),
                    ],
                ]
            );

            $currentBalance = $projectedBalance;
        }

        return $projections;
    }

    protected function getCurrentBalance(User $user): float
    {
        $totalIncome = (float) $user->transactions()
            ->where('type', 'income')
            ->sum('amount');

        $allExpenses = $user->transactions()
            ->where('type', 'expense')
            ->get();

        $expensesWithoutSavings = $allExpenses->filter(function ($transaction) {
            $metadata = $transaction->metadata ?? [];

            return ! isset($metadata['savings_goal_deposit_id']);
        });

        $totalExpenses = (float) $expensesWithoutSavings->sum('amount');

        $totalSavings = (float) $user->savingsGoals()
            ->with('deposits')
            ->get()
            ->sum(fn ($goal) => $goal->deposits->sum('amount'));

        return $totalIncome - $totalExpenses - $totalSavings;
    }

    protected function calculateProjectedIncome(User $user, Carbon $date): float
    {
        $recurringIncome = RecurringTransaction::where('user_id', $user->id)
            ->where('type', 'income')
            ->where('is_active', true)
            ->where('start_date', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', $date);
            })
            ->sum('amount');

        $averageIncome = $this->getAverageMonthlyIncome($user);

        return $recurringIncome + ($averageIncome * 0.3); // 30% da média como projeção
    }

    protected function calculateProjectedExpenses(User $user, Carbon $date): float
    {
        $recurringExpenses = RecurringTransaction::where('user_id', $user->id)
            ->where('type', 'expense')
            ->where('is_active', true)
            ->where('start_date', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', $date);
            })
            ->sum('amount');

        $averageExpenses = $this->getAverageMonthlyExpenses($user);

        return $recurringExpenses + ($averageExpenses * 0.7); // 70% da média como projeção
    }

    protected function getAverageMonthlyIncome(User $user): float
    {
        $last6Months = $user->transactions()
            ->where('type', 'income')
            ->where('date', '>=', now()->subMonths(6))
            ->get()
            ->groupBy(function ($transaction) {
                return $transaction->date->format('Y-m');
            })
            ->map(function ($transactions) {
                return $transactions->sum('amount');
            });

        if ($last6Months->isEmpty()) {
            return 0;
        }

        return (float) $last6Months->average();
    }

    protected function getAverageMonthlyExpenses(User $user): float
    {
        $last6Months = $user->transactions()
            ->where('type', 'expense')
            ->where('date', '>=', now()->subMonths(6))
            ->get()
            ->filter(function ($transaction) {
                $metadata = $transaction->metadata ?? [];

                return ! isset($metadata['savings_goal_deposit_id']);
            })
            ->groupBy(function ($transaction) {
                return $transaction->date->format('Y-m');
            })
            ->map(function ($transactions) {
                return $transactions->sum('amount');
            });

        if ($last6Months->isEmpty()) {
            return 0;
        }

        return (float) $last6Months->average();
    }
}
