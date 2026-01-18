<?php

namespace App\DataTransferObjects;

class FinancialDataDTO
{
    public function __construct(
        public readonly float $availableBalance,
        public readonly float $monthlyIncome,
        public readonly float $monthlyExpenses,
        public readonly float $monthlyBalance,
        public readonly float $totalIncomeAllTime,
        public readonly float $totalExpensesAllTime,
        public readonly float $totalSavings,
        public readonly array $expensesByCategoryThisMonth,
        public readonly string $currentMonth,
        public readonly string $currentDate,
        public readonly float $previousMonthIncome,
        public readonly float $previousMonthExpenses,
        public readonly float $incomeVariationPercentage,
        public readonly float $expensesVariationPercentage,
        public readonly array $monthlyEvolution,
        public readonly array $yearlyEvolution,
        public readonly array $exceededBudgets,
        public readonly array $savingsGoals,
        public readonly array $financialProjections,
    ) {}

    /**
     * Cria DTO a partir de array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            availableBalance: (float) ($data['available_balance'] ?? 0),
            monthlyIncome: (float) ($data['monthly_income'] ?? 0),
            monthlyExpenses: (float) ($data['monthly_expenses'] ?? 0),
            monthlyBalance: (float) ($data['monthly_balance'] ?? 0),
            totalIncomeAllTime: (float) ($data['total_income_all_time'] ?? 0),
            totalExpensesAllTime: (float) ($data['total_expenses_all_time'] ?? 0),
            totalSavings: (float) ($data['total_savings'] ?? 0),
            expensesByCategoryThisMonth: $data['expenses_by_category_this_month'] ?? [],
            currentMonth: $data['current_month'] ?? '',
            currentDate: $data['current_date'] ?? '',
            previousMonthIncome: (float) ($data['previous_month_income'] ?? 0),
            previousMonthExpenses: (float) ($data['previous_month_expenses'] ?? 0),
            incomeVariationPercentage: (float) ($data['income_variation_percentage'] ?? 0),
            expensesVariationPercentage: (float) ($data['expenses_variation_percentage'] ?? 0),
            monthlyEvolution: $data['monthly_evolution'] ?? [],
            yearlyEvolution: $data['yearly_evolution'] ?? [],
            exceededBudgets: $data['exceeded_budgets'] ?? [],
            savingsGoals: $data['savings_goals'] ?? [],
            financialProjections: $data['financial_projections'] ?? [],
        );
    }

    /**
     * Converte DTO para array
     */
    public function toArray(): array
    {
        return [
            'available_balance' => $this->availableBalance,
            'monthly_income' => $this->monthlyIncome,
            'monthly_expenses' => $this->monthlyExpenses,
            'monthly_balance' => $this->monthlyBalance,
            'total_income_all_time' => $this->totalIncomeAllTime,
            'total_expenses_all_time' => $this->totalExpensesAllTime,
            'total_savings' => $this->totalSavings,
            'expenses_by_category_this_month' => $this->expensesByCategoryThisMonth,
            'current_month' => $this->currentMonth,
            'current_date' => $this->currentDate,
            'previous_month_income' => $this->previousMonthIncome,
            'previous_month_expenses' => $this->previousMonthExpenses,
            'income_variation_percentage' => $this->incomeVariationPercentage,
            'expenses_variation_percentage' => $this->expensesVariationPercentage,
            'monthly_evolution' => $this->monthlyEvolution,
            'yearly_evolution' => $this->yearlyEvolution,
            'exceeded_budgets' => $this->exceededBudgets,
            'savings_goals' => $this->savingsGoals,
            'financial_projections' => $this->financialProjections,
        ];
    }
}
