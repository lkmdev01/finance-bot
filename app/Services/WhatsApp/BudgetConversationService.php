<?php

namespace App\Services\WhatsApp;

use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class BudgetConversationService
{
    public function buildReply(User $user, string $message, array $state = []): array
    {
        $context = $this->buildContext($user, $message, $state);
        $budgets = $this->loadBudgets($user, $context);

        if ($budgets->isEmpty()) {
            return [
                'reply' => $this->buildEmptyReply($context),
                'entities' => $this->buildEntities($context),
            ];
        }

        if ($context['comparison_mode'] === 'single') {
            return [
                'reply' => $this->buildPortfolioComparisonReply($user, $budgets, $context),
                'entities' => $this->buildEntities($context, [
                    'comparison_mode' => 'single',
                    'category_count' => $budgets->count(),
                ]),
            ];
        }

        if ($context['comparison_mode'] === 'pair' && count($context['category_names']) >= 2) {
            return [
                'reply' => $this->buildPairComparisonReply($budgets, $context),
                'entities' => $this->buildEntities($context, [
                    'comparison_mode' => 'pair',
                    'category_count' => $budgets->count(),
                ]),
            ];
        }

        if (! empty($context['category_names'])) {
            return [
                'reply' => $this->buildCategoryReply($budgets, $context),
                'entities' => $this->buildEntities($context, [
                    'category_name' => $budgets->count() === 1 ? $budgets->first()->category?->name : null,
                    'category_names' => $budgets->pluck('category.name')->filter()->values()->all(),
                ]),
            ];
        }

        return [
            'reply' => $this->buildSummaryReply($user, $budgets, $context),
            'entities' => $this->buildEntities($context, [
                'category_count' => $budgets->count(),
            ]),
        ];
    }

    private function buildContext(User $user, string $message, array $state): array
    {
        $normalized = $this->normalize($message);
        $anchor = CarbonImmutable::now();
        $lastEntities = $this->resolveRelevantEntities($state);
        $period = $this->resolvePeriod($normalized, $lastEntities, $anchor);
        $availableCategories = $this->buildCategoryIndex($user);
        $categoryNames = $this->resolveCategories($normalized, $availableCategories, $lastEntities);

        return [
            'original_message' => $message,
            'normalized_message' => $normalized,
            'period' => $period,
            'category_names' => $categoryNames,
            'comparison_mode' => $this->resolveComparisonMode($normalized, $categoryNames, $state),
            'available_categories' => $availableCategories,
        ];
    }

    private function buildCategoryIndex(User $user): Collection
    {
        return $user->categories()
            ->where('type', 'expense')
            ->orderBy('name')
            ->get()
            ->map(fn (Category $category) => [
                'name' => $category->name,
                'normalized' => $this->normalize($category->name),
            ]);
    }

    private function resolvePeriod(string $message, array $lastEntities, CarbonImmutable $anchor): array
    {
        $reference = $anchor;
        $scope = 'current_month';

        if ($this->containsAny($message, ['mes passado', 'mês passado', 'ultimo mes', 'último mês'])) {
            $reference = $anchor->subMonthNoOverflow();
            $scope = 'previous_month';
        } elseif ($this->containsAny($message, ['esse mes', 'esse mês', 'este mes', 'este mês', 'neste mes', 'neste mês'])) {
            $scope = 'current_month';
        } elseif ($this->containsAny($message, ['ano passado', 'ultimo ano', 'último ano'])) {
            $reference = $anchor->subYear();
            $scope = 'previous_year';
        }

        $monthMap = [
            'janeiro' => 1,
            'fevereiro' => 2,
            'marco' => 3,
            'março' => 3,
            'abril' => 4,
            'maio' => 5,
            'junho' => 6,
            'julho' => 7,
            'agosto' => 8,
            'setembro' => 9,
            'outubro' => 10,
            'novembro' => 11,
            'dezembro' => 12,
        ];

        foreach ($monthMap as $label => $monthNumber) {
            if (str_contains($message, $label)) {
                $reference = $reference->month($monthNumber);
                $scope = 'specific_month';
                break;
            }
        }

        if (preg_match('/\b(20\d{2})\b/u', $message, $matches)) {
            $reference = $reference->year((int) $matches[1]);
            $scope = $scope === 'current_month' ? 'specific_year' : $scope;
        }

        if ($this->containsAny($message, ['anual', 'ano inteiro'])) {
            $scope = 'yearly_focus';
        }

        if ($scope === 'current_month'
            && ($lastEntities['period_scope'] ?? null) === 'specific_month'
            && $this->containsAny($message, ['mesmo periodo', 'mesmo período'])
        ) {
            $reference = CarbonImmutable::create(
                (int) ($lastEntities['year'] ?? $anchor->year),
                (int) ($lastEntities['month'] ?? $anchor->month),
                1
            );
            $scope = 'specific_month';
        }

        return [
            'scope' => $scope,
            'year' => $reference->year,
            'month' => $scope === 'yearly_focus' || $scope === 'previous_year' ? null : $reference->month,
            'label' => $scope === 'previous_year'
                ? (string) $reference->year
                : $reference->locale('pt_BR')->translatedFormat('F/Y'),
        ];
    }

    private function resolveCategories(string $message, Collection $availableCategories, array $lastEntities): array
    {
        $matches = [];

        foreach ($availableCategories as $category) {
            if ($category['normalized'] !== '' && str_contains($message, $category['normalized'])) {
                $matches[] = $category['name'];
            }
        }

        $matches = array_values(array_unique($matches));

        if (! empty($matches)) {
            return $matches;
        }

        if (($lastEntities['topic'] ?? null) === 'budget'
            && ! empty($lastEntities['category_name'])
            && $this->isTemporalFollowUp($message)
        ) {
            return [(string) $lastEntities['category_name']];
        }

        return [];
    }

    private function resolveComparisonMode(string $message, array $categoryNames, array $state): ?string
    {
        if (count($categoryNames) >= 2 && $this->containsAny($message, ['compar', 'versus', 'vs'])) {
            return 'pair';
        }

        if ($this->containsAny($message, ['mais apertad', 'mais comprometid', 'mais folga', 'mais livre', 'mais sobrando'])) {
            return 'single';
        }

        if (($state['last_action'] ?? null) === 'query_budgets'
            && $this->containsAny($message, ['qual está mais apertad', 'qual tem mais folga', 'qual ta mais apertad', 'qual ta com mais folga'])
        ) {
            return 'single';
        }

        return null;
    }

    private function loadBudgets(User $user, array $context): Collection
    {
        $period = $context['period'];

        $query = Budget::query()
            ->with('category')
            ->where('user_id', $user->id)
            ->where('year', $period['year']);

        if ($period['scope'] === 'yearly_focus' || $period['scope'] === 'previous_year') {
            $query->where('period', 'yearly');
        } elseif (in_array($period['scope'], ['specific_month', 'previous_month', 'current_month'], true)) {
            $month = $period['month'] ?? CarbonImmutable::now()->month;
            $query->where(function ($builder) use ($month, $period) {
                $builder->where(function ($monthly) use ($month) {
                    $monthly->where('period', 'monthly')->where('month', $month);
                });

                if ($period['scope'] === 'current_month') {
                    $builder->orWhere('period', 'yearly');
                }
            });
        }

        $budgets = $query->get()->filter(fn (Budget $budget) => $budget->category !== null)->values();

        if (! empty($context['category_names'])) {
            $normalizedTargets = array_map(fn ($name) => $this->normalize($name), $context['category_names']);
            $budgets = $budgets->filter(function (Budget $budget) use ($normalizedTargets) {
                return in_array($this->normalize((string) $budget->category?->name), $normalizedTargets, true);
            })->values();
        }

        return $budgets->sortBy(fn (Budget $budget) => mb_strtolower((string) $budget->category?->name))->values();
    }

    private function buildEmptyReply(array $context): string
    {
        $category = $context['category_names'][0] ?? null;
        $periodLabel = $context['period']['label'];

        if ($category !== null) {
            return "Não encontrei orçamento para {$category} em {$periodLabel}. Se quiser, eu posso listar as categorias que já têm orçamento ou criar um novo limite para você.";
        }

        return "Você ainda não tem orçamentos cadastrados para {$periodLabel}. Se quiser, eu posso criar um orçamento por categoria ou comparar outro período.";
    }

    private function buildSummaryReply(User $user, Collection $budgets, array $context): string
    {
        $periodLabel = $context['period']['label'];
        $lines = $budgets->map(fn (Budget $budget) => $this->formatBudgetLine($budget))->implode("\n");
        $tightest = $this->findTightestBudget($budgets);
        $widest = $this->findMostComfortableBudget($budgets);
        $advisor = app(FinancialConversationAdvisor::class);

        $insight = [];
        if ($tightest && $tightest->percentage_used > 0) {
            $insight[] = sprintf(
                'Hoje, %s é a categoria mais apertada, com %s do limite usado.',
                $tightest->category?->name,
                $this->formatPercentage($tightest->percentage_used)
            );
        }

        if ($widest && $widest->id !== $tightest?->id && $widest->remaining > 0) {
            $insight[] = sprintf(
                '%s é a que tem mais folga, com R$ %s restantes.',
                $widest->category?->name,
                $this->formatMoney($widest->remaining)
            );
        }

        $suggestion = 'Se quiser, eu posso abrir uma categoria específica, comparar com o mês passado ou te dizer qual está mais apertada.';
        $proactive = $advisor->budgetSummaryInsight($user, $budgets);

        return trim(
            "Seus orçamentos de {$periodLabel}:\n{$lines}\n\n" .
            implode(' ', array_filter([implode(' ', $insight), $proactive, $suggestion]))
        );
    }

    private function buildCategoryReply(Collection $budgets, array $context): string
    {
        if ($budgets->count() > 1) {
            $periodLabel = $context['period']['label'];
            $lines = $budgets->map(fn (Budget $budget) => $this->formatBudgetLine($budget))->implode("\n");

            return "Encontrei estes orçamentos em {$periodLabel}:\n{$lines}\n\nSe quiser, eu posso comparar essas categorias entre si ou olhar o mês passado.";
        }

        /** @var Budget $budget */
        $budget = $budgets->first();
        $category = $budget->category?->name ?? 'essa categoria';
        $periodLabel = $context['period']['label'];
        $advisor = app(FinancialConversationAdvisor::class);

        $reply = sprintf(
            'Seu orçamento de %s em %s é R$ %s. Você usou R$ %s e ainda tem R$ %s livres.',
            $category,
            $periodLabel,
            $this->formatMoney($budget->amount),
            $this->formatMoney($budget->spent),
            $this->formatMoney($budget->remaining)
        );

        if ($budget->percentage_used >= 90) {
            $reply .= ' Ele já está bem perto do limite.';
        } elseif ($budget->percentage_used >= 70) {
            $reply .= ' Ele já consumiu boa parte do limite.';
        } else {
            $reply .= ' Ainda há uma folga confortável nesse orçamento.';
        }

        $proactive = $advisor->budgetCategoryInsight($budget);
        if ($proactive !== null) {
            $reply .= ' '.$proactive;
        }

        $reply .= "\n\nSe quiser, eu posso comparar {$category} com o mês passado, com outra categoria ou listar os gastos que entram nesse orçamento.";

        return $reply;
    }

    private function buildPortfolioComparisonReply(User $user, Collection $budgets, array $context): string
    {
        $tightest = $this->findTightestBudget($budgets);
        $widest = $this->findMostComfortableBudget($budgets);
        $periodLabel = $context['period']['label'];
        $advisor = app(FinancialConversationAdvisor::class);

        if (! $tightest || ! $widest) {
            return $this->buildSummaryReply($user, $budgets, $context);
        }

        if ($tightest->percentage_used <= 0 && $widest->remaining > 0) {
            $reply = sprintf(
                'Comparando seus orçamentos de %s, ainda não houve consumo registrado. Hoje, %s é a categoria com mais folga disponível, com R$ %s restantes.',
                $periodLabel,
                $widest->category?->name,
                $this->formatMoney($widest->remaining)
            );
        } else {
            $reply = sprintf(
                'Comparando seus orçamentos de %s, %s é a categoria mais apertada, com %s do limite usado, e %s é a que tem mais folga, com R$ %s restantes.',
                $periodLabel,
                $tightest->category?->name,
                $this->formatPercentage($tightest->percentage_used),
                $widest->category?->name,
                $this->formatMoney($widest->remaining)
            );
        }

        $proactive = $advisor->budgetSummaryInsight($user, $budgets);
        if ($proactive !== null) {
            $reply .= ' '.$proactive;
        }

        $reply .= "\n\nSe quiser, eu posso detalhar qualquer uma dessas categorias ou comparar com outro período.";

        return $reply;
    }

    private function buildPairComparisonReply(Collection $budgets, array $context): string
    {
        if ($budgets->count() < 2) {
            return $this->buildCategoryReply($budgets, $context);
        }

        $sorted = $budgets->sortByDesc(fn (Budget $budget) => $budget->percentage_used)->values();
        $first = $sorted[0];
        $second = $sorted[1];
        $periodLabel = $context['period']['label'];

        $lines = $sorted->map(function (Budget $budget) {
            return sprintf(
                '- %s: limite R$ %s | gasto R$ %s | restante R$ %s',
                $budget->category?->name,
                $this->formatMoney($budget->amount),
                $this->formatMoney($budget->spent),
                $this->formatMoney($budget->remaining)
            );
        })->implode("\n");

        $reply = sprintf(
            "Comparando essas categorias em %s:\n%s\n\nHoje, %s está mais perto do limite do que %s.",
            $periodLabel,
            $lines,
            $first->category?->name,
            $second->category?->name
        );

        $reply .= ' Se quiser, eu também posso puxar o mês passado para ver a evolução.';

        return $reply;
    }

    private function buildEntities(array $context, array $extra = []): array
    {
        return array_filter(array_merge([
            'topic' => 'budget',
            'period_scope' => $context['period']['scope'],
            'period_label' => $context['period']['label'],
            'year' => $context['period']['year'],
            'month' => $context['period']['month'],
            'category_name' => count($context['category_names']) === 1 ? $context['category_names'][0] : null,
            'category_names' => count($context['category_names']) > 1 ? $context['category_names'] : null,
        ], $extra), fn ($value) => $value !== null && $value !== [] && $value !== '');
    }

    private function formatBudgetLine(Budget $budget): string
    {
        $period = $budget->period === 'yearly' ? 'anual' : 'mensal';

        return sprintf(
            '- %s: limite R$ %s | gasto R$ %s | restante R$ %s (%s)',
            $budget->category?->name ?? 'Sem categoria',
            $this->formatMoney($budget->amount),
            $this->formatMoney($budget->spent),
            $this->formatMoney($budget->remaining),
            $period
        );
    }

    private function findTightestBudget(Collection $budgets): ?Budget
    {
        return $budgets->sortByDesc(fn (Budget $budget) => [$budget->percentage_used, -$budget->remaining])->first();
    }

    private function findMostComfortableBudget(Collection $budgets): ?Budget
    {
        return $budgets->sortByDesc(fn (Budget $budget) => [$budget->remaining, -$budget->percentage_used])->first();
    }

    private function formatMoney(float|string $amount): string
    {
        return number_format((float) $amount, 2, ',', '.');
    }

    private function formatPercentage(float $value): string
    {
        return number_format($value, 0, ',', '.').'%';
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $converted !== false ? $converted : $value;
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

    private function isTemporalFollowUp(string $message): bool
    {
        return $this->containsAny($message, ['mes passado', 'mês passado', 'esse mes', 'esse mês', 'este mes', 'este mês', 'ano passado']);
    }

    private function resolveRelevantEntities(array $state): array
    {
        $lastEntities = $state['last_entities'] ?? [];

        if (($lastEntities['topic'] ?? null) === 'budget') {
            return $lastEntities;
        }

        foreach (($state['recent_contexts'] ?? []) as $context) {
            $entities = $context['entities'] ?? [];

            if (($entities['topic'] ?? null) === 'budget') {
                return $entities;
            }
        }

        return $lastEntities;
    }
}
