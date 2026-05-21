<?php

namespace App\Services\WhatsApp;

use App\Models\Budget;
use App\Models\SavingsGoal;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinancialDataCalculator;
use Carbon\CarbonInterface;
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

    public function savingsSummaryInsight(Collection $goals): ?string
    {
        if ($goals->isEmpty()) {
            return null;
        }

        $nearestTarget = $goals
            ->filter(fn (SavingsGoal $goal) => ! $goal->is_completed && $goal->target_date !== null)
            ->sortBy('target_date')
            ->first();

        if ($nearestTarget instanceof SavingsGoal) {
            $daysLeft = now()->startOfDay()->diffInDays($nearestTarget->target_date->copy()->startOfDay(), false);

            if ($daysLeft <= 60 && $nearestTarget->progress_percentage < 50) {
                return sprintf(
                    'Insight rapido: %s vence em %d dias e ainda esta com %s da meta.',
                    $nearestTarget->name,
                    max(0, $daysLeft),
                    $this->formatPercentage((float) $nearestTarget->progress_percentage)
                );
            }
        }

        $closestGoal = $goals->sortByDesc(fn (SavingsGoal $goal) => $goal->progress_percentage)->first();

        if ($closestGoal instanceof SavingsGoal && $closestGoal->progress_percentage >= 75) {
            return sprintf(
                '%s ja esta com %s da meta e parece bem encaminhada.',
                $closestGoal->name,
                $this->formatPercentage((float) $closestGoal->progress_percentage)
            );
        }

        return null;
    }

    public function savingsGoalInsight(SavingsGoal $goal): ?string
    {
        if ($goal->is_completed) {
            return 'Boa noticia: essa meta ja foi concluida.';
        }

        if ($goal->target_date instanceof CarbonInterface) {
            $daysLeft = now()->startOfDay()->diffInDays($goal->target_date->copy()->startOfDay(), false);

            if ($daysLeft <= 45 && $goal->progress_percentage < 60) {
                return sprintf(
                    'Vale reforcar essa meta: faltam %d dias e ela esta com %s de progresso.',
                    max(0, $daysLeft),
                    $this->formatPercentage((float) $goal->progress_percentage)
                );
            }
        }

        if ($goal->progress_percentage >= 80) {
            return 'Ela ja esta bem perto de ser concluida.';
        }

        return null;
    }

    public function subscriptionSummaryInsight(Collection $subscriptions): ?string
    {
        if ($subscriptions->isEmpty()) {
            return null;
        }

        $dueSoon = $subscriptions
            ->filter(fn (Subscription $subscription) => $subscription->is_active && $subscription->next_due_date !== null)
            ->sortBy('next_due_date')
            ->first(function (Subscription $subscription) {
                return $subscription->next_due_date->copy()->startOfDay()->lte(now()->addDays(7)->startOfDay());
            });

        if ($dueSoon instanceof Subscription) {
            $daysLeft = now()->startOfDay()->diffInDays($dueSoon->next_due_date->copy()->startOfDay(), false);

            if ($daysLeft < 0) {
                return sprintf(
                    'Alerta rapido: %s ja esta vencida e pede atencao.',
                    $dueSoon->name
                );
            }

            return sprintf(
                'Lembrete rapido: %s vence em %d dias.',
                $dueSoon->name,
                $daysLeft
            );
        }

        $monthlyTotal = $subscriptions
            ->filter(fn (Subscription $subscription) => $subscription->is_active && $subscription->billing_cycle === 'monthly')
            ->sum('amount');

        if ($monthlyTotal >= 300) {
            return sprintf(
                'Suas assinaturas mensais somam R$ %s neste momento.',
                $this->formatMoney($monthlyTotal)
            );
        }

        return null;
    }

    public function subscriptionItemInsight(Subscription $subscription): ?string
    {
        if (! $subscription->is_active) {
            return 'Ela esta inativa no momento.';
        }

        if ($subscription->next_due_date instanceof CarbonInterface) {
            $daysLeft = now()->startOfDay()->diffInDays($subscription->next_due_date->copy()->startOfDay(), false);

            if ($daysLeft < 0) {
                return 'Ela ja passou da data prevista de cobranca.';
            }

            if ($daysLeft <= 5) {
                return sprintf('Ela vence em %d dias.', $daysLeft);
            }
        }

        return null;
    }

    public function projectionSummaryInsight(Collection $projections): ?string
    {
        if ($projections->isEmpty()) {
            return null;
        }

        $negativeProjection = $projections->first(function ($projection) {
            return (float) ($projection['projected_balance'] ?? 0) < 0;
        });

        if (is_array($negativeProjection)) {
            return sprintf(
                'Alerta rapido: a projecao de %s fica negativa em R$ %s.',
                $negativeProjection['month'] ?? ($negativeProjection['date'] ?? 'um periodo futuro'),
                $this->formatMoney(abs((float) $negativeProjection['projected_balance']))
            );
        }

        $lowestProjection = $projections->sortBy('projected_balance')->first();
        if (is_array($lowestProjection)) {
            return sprintf(
                'Seu menor saldo projetado aparece em %s, com R$ %s.',
                $lowestProjection['month'] ?? ($lowestProjection['date'] ?? 'um periodo futuro'),
                $this->formatMoney((float) $lowestProjection['projected_balance'])
            );
        }

        return null;
    }

    public function projectionPointInsight(array $projection): ?string
    {
        $balance = (float) ($projection['projected_balance'] ?? 0);
        $income = (float) ($projection['projected_income'] ?? 0);
        $expenses = (float) ($projection['projected_expenses'] ?? 0);

        if ($balance < 0) {
            return sprintf(
                'Esse cenario projeta saldo negativo de R$ %s.',
                $this->formatMoney(abs($balance))
            );
        }

        if ($expenses > $income && $income > 0) {
            return 'Nesse periodo, as despesas projetadas passam das entradas previstas.';
        }

        if ($balance > 0 && $expenses <= $income) {
            return 'Nesse ritmo, o saldo segue positivo nesse horizonte.';
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
