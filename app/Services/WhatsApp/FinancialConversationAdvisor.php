<?php

namespace App\Services\WhatsApp;

use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinancialDataCalculator;
use Illuminate\Support\Collection;

class FinancialConversationAdvisor
{
    public function __construct(
        private readonly FinancialDataCalculator $financialDataCalculator
    ) {}

    public function budgetSummaryInsight(User $user, Collection $budgets): ?string
    {
        if ($budgets->isEmpty()) {
            return null;
        }

        $highestUsage = $budgets->sortByDesc(fn (Budget $budget) => $budget->percentage_used)->first();
        $lowestRemaining = $budgets->sortBy('remaining')->first();

        if ($highestUsage && $highestUsage->percentage_used >= 100) {
            return sprintf(
                'Alerta rápido: %s já passou do limite e merece atenção agora.',
                $highestUsage->category?->name
            );
        }

        if ($highestUsage && $highestUsage->percentage_used >= 80) {
            return sprintf(
                'Alerta rápido: %s já consumiu %s do orçamento.',
                $highestUsage->category?->name,
                $this->formatPercentage($highestUsage->percentage_used)
            );
        }

        if ($highestUsage && $highestUsage->percentage_used <= 0) {
            return 'Até aqui, nenhum orçamento teve consumo registrado neste período.';
        }

        if ($lowestRemaining && $lowestRemaining->remaining <= 150) {
            return sprintf(
                'Vale acompanhar %s: restam R$ %s até o fim do período.',
                $lowestRemaining->category?->name,
                $this->formatMoney($lowestRemaining->remaining)
            );
        }

        return null;
    }

    public function budgetCategoryInsight(Budget $budget): ?string
    {
        if ($budget->percentage_used >= 100) {
            return 'Alerta rápido: esse orçamento já foi estourado.';
        }

        if ($budget->percentage_used >= 85) {
            return sprintf(
                'Alerta rápido: você já usou %s desse limite.',
                $this->formatPercentage($budget->percentage_used)
            );
        }

        if ($budget->remaining <= 100) {
            return sprintf(
                'Sobra pouco espaço aqui: apenas R$ %s.',
                $this->formatMoney($budget->remaining)
            );
        }

        return null;
    }

    public function transactionSummaryInsight(User $user, Collection $transactions, array $context): ?string
    {
        if ($transactions->isEmpty()) {
            return null;
        }

        $financialData = $this->financialDataCalculator->calculate($user);
        $type = $context['type'] ?? 'expense';

        if ($type === 'expense') {
            $variation = (float) ($financialData['expenses_variation_percentage'] ?? 0);

            if ($variation >= 25) {
                return sprintf(
                    'Insight rápido: seus gastos subiram %s em relação ao mês passado.',
                    $this->formatPercentage($variation)
                );
            }

            $topCategory = $financialData['expenses_by_category_this_month'][0] ?? null;
            if ($topCategory && ! empty($topCategory['category_name'])) {
                return sprintf(
                    '%s é a categoria que mais pesou no mês até aqui.',
                    $topCategory['category_name']
                );
            }
        }

        if ($type === 'income') {
            $variation = (float) ($financialData['income_variation_percentage'] ?? 0);

            if ($variation >= 20) {
                return sprintf(
                    'Insight rápido: suas entradas cresceram %s em relação ao mês passado.',
                    $this->formatPercentage($variation)
                );
            }
        }

        return null;
    }

    public function transactionCategoryInsight(User $user, Collection $transactions, array $context): ?string
    {
        if ($transactions->isEmpty()) {
            return null;
        }

        $total = (float) $transactions->sum('amount');
        $average = $transactions->count() > 0 ? $total / $transactions->count() : 0;

        if ($transactions->count() >= 3 && $average >= 100) {
            return sprintf(
                'Padrão útil: essa categoria teve %d lançamentos com ticket médio de R$ %s.',
                $transactions->count(),
                $this->formatMoney($average)
            );
        }

        $financialData = $this->financialDataCalculator->calculate($user);
        $topCategory = $financialData['expenses_by_category_this_month'][0]['category_name'] ?? null;
        $currentCategory = $transactions->first()?->category?->name;

        if ($currentCategory && $topCategory === $currentCategory) {
            return sprintf(
                'Atenção natural: %s lidera seus gastos no período atual.',
                $currentCategory
            );
        }

        return null;
    }

    public function combineSuggestions(string ...$parts): ?string
    {
        $filtered = array_values(array_filter(array_map('trim', $parts)));

        if ($filtered === []) {
            return null;
        }

        return implode(' ', $filtered);
    }

    private function formatMoney(float|string $value): string
    {
        return number_format((float) $value, 2, ',', '.');
    }

    private function formatPercentage(float $value): string
    {
        return number_format($value, 0, ',', '.').'%';
    }
}
