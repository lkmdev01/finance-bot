<?php

namespace App\Services\WhatsApp;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class TransactionConversationService
{
    public function buildReply(User $user, string $message, array $state = [], ?string $action = null): array
    {
        $context = $this->buildContext($user, $message, $state, $action);
        $transactions = $this->loadTransactions($user, $context);

        if ($transactions->isEmpty()) {
            return [
                'reply' => $this->buildEmptyReply($context),
                'entities' => $this->buildEntities($context),
            ];
        }

        if ($context['mode'] === 'category' && $context['comparison_mode'] === 'pair') {
            return [
                'reply' => $this->buildCategoryComparisonReply($transactions, $context),
                'entities' => $this->buildEntities($context),
            ];
        }

        if ($context['mode'] === 'category') {
            return [
                'reply' => $this->buildCategoryReply($user, $transactions, $context),
                'entities' => $this->buildEntities($context),
            ];
        }

        return [
            'reply' => $this->buildTransactionListReply($user, $transactions, $context),
            'entities' => $this->buildEntities($context),
        ];
    }

    private function buildContext(User $user, string $message, array $state, ?string $action): array
    {
        $normalized = $this->normalize($message);
        $availableCategories = $this->buildCategoryIndex($user);
        $lastEntities = $this->resolveRelevantEntities($state);

        $mode = $action === 'query_category' ? 'category' : 'transactions';
        if ($mode !== 'category' && ! empty($lastEntities['category_name']) && $this->looksLikeCategoryFollowUp($normalized, $lastEntities)) {
            $mode = 'category';
        }

        $type = $this->resolveType($normalized, $lastEntities);
        $period = $this->resolvePeriod($normalized, $lastEntities);
        $categoryNames = $this->resolveCategories($normalized, $availableCategories, $lastEntities, $mode);
        $comparisonMode = $this->resolveComparisonMode($normalized, $categoryNames, $mode);

        return [
            'normalized_message' => $normalized,
            'mode' => $mode,
            'type' => $type,
            'period' => $period,
            'category_names' => $categoryNames,
            'comparison_mode' => $comparisonMode,
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

    private function resolveType(string $message, array $lastEntities): ?string
    {
        if ($this->containsAny($message, ['receita', 'receitas', 'ganho', 'ganhos', 'entrada', 'entradas'])) {
            return 'income';
        }

        if ($this->containsAny($message, ['gasto', 'gastos', 'despesa', 'despesas'])) {
            return 'expense';
        }

        return $lastEntities['transaction_type'] ?? 'expense';
    }

    private function resolvePeriod(string $message, array $lastEntities): array
    {
        $reference = CarbonImmutable::now();
        $scope = 'current_month';

        if ($this->containsAny($message, ['mes passado', 'mês passado', 'ultimo mes', 'último mês'])) {
            $reference = $reference->subMonthNoOverflow();
            $scope = 'previous_month';
        } elseif ($this->containsAny($message, ['hoje'])) {
            $scope = 'today';
        } elseif ($this->containsAny($message, ['ontem'])) {
            $reference = $reference->subDay();
            $scope = 'yesterday';
        } elseif ($this->containsAny($message, ['esse mes', 'esse mês', 'este mes', 'este mês'])) {
            $scope = 'current_month';
        }

        if ($scope === 'current_month'
            && ! empty($lastEntities['period_scope'])
            && $this->containsAny($message, ['mesmo periodo', 'mesmo período'])
        ) {
            $scope = (string) $lastEntities['period_scope'];
            $reference = CarbonImmutable::create(
                (int) ($lastEntities['year'] ?? $reference->year),
                (int) ($lastEntities['month'] ?? $reference->month),
                max(1, (int) ($lastEntities['day'] ?? 1))
            );
        }

        return [
            'scope' => $scope,
            'year' => $reference->year,
            'month' => $reference->month,
            'day' => in_array($scope, ['today', 'yesterday'], true) ? $reference->day : null,
            'label' => match ($scope) {
                'today' => 'hoje',
                'yesterday' => 'ontem',
                default => $reference->locale('pt_BR')->translatedFormat('F/Y'),
            },
        ];
    }

    private function resolveCategories(string $message, Collection $availableCategories, array $lastEntities, string $mode): array
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

        if ($mode === 'category' && ! empty($lastEntities['category_name']) && $this->looksLikeTemporalFollowUp($message)) {
            return [(string) $lastEntities['category_name']];
        }

        return [];
    }

    private function resolveComparisonMode(string $message, array $categoryNames, string $mode): ?string
    {
        if ($mode !== 'category') {
            return null;
        }

        if (count($categoryNames) >= 2 || $this->containsAny($message, ['compare', 'comparar', 'versus', 'vs'])) {
            return 'pair';
        }

        return null;
    }

    private function loadTransactions(User $user, array $context): Collection
    {
        $query = Transaction::query()
            ->with('category')
            ->where('user_id', $user->id)
            ->when($context['type'], fn ($builder, $type) => $builder->where('type', $type));

        $period = $context['period'];

        if ($period['scope'] === 'today' || $period['scope'] === 'yesterday') {
            $date = CarbonImmutable::create($period['year'], $period['month'], $period['day']);
            $query->whereDate('date', $date->toDateString());
        } else {
            $query->whereYear('date', $period['year'])->whereMonth('date', $period['month']);
        }

        if (! empty($context['category_names'])) {
            $normalizedTargets = array_map(fn ($name) => $this->normalize($name), $context['category_names']);
            $query->whereHas('category', function ($builder) use ($normalizedTargets) {
                foreach ($normalizedTargets as $target) {
                    $builder->orWhereRaw('LOWER(name) LIKE ?', ['%' . $target . '%']);
                }
            });
        }

        return $query->latest('date')->latest('id')->limit($context['mode'] === 'transactions' ? 10 : 100)->get();
    }

    private function buildEmptyReply(array $context): string
    {
        if ($context['mode'] === 'category' && ! empty($context['category_names'])) {
            $category = $context['category_names'][0];

            return "Não encontrei {$this->labelForType($context['type'])} em {$category} para {$context['period']['label']}. Se quiser, eu posso comparar outra categoria ou olhar outro período.";
        }

        return "Não encontrei {$this->labelForType($context['type'])} para {$context['period']['label']}. Se quiser, eu posso filtrar por categoria ou comparar com o mês passado.";
    }

    private function buildTransactionListReply(User $user, Collection $transactions, array $context): string
    {
        $title = match ($context['type']) {
            'income' => 'Suas receitas',
            'expense' => 'Seus gastos',
            default => 'Suas transações',
        };

        $lines = $transactions->take(5)->map(function (Transaction $transaction) {
            $date = $transaction->date?->format('d/m') ?? now()->format('d/m');
            $label = $transaction->description ?: ($transaction->category?->name ?? 'Sem descrição');
            $category = $transaction->category?->name ? " ({$transaction->category->name})" : '';
            $amount = number_format((float) $transaction->amount, 2, ',', '.');

            return "- {$date} - {$label}{$category}: R$ {$amount}";
        })->implode("\n");

        $total = number_format((float) $transactions->sum('amount'), 2, ',', '.');
        $advisor = app(FinancialConversationAdvisor::class);
        $insight = $advisor->transactionSummaryInsight($user, $transactions, $context);
        $reply = "{$title} de {$context['period']['label']}:\n{$lines}\n\nTotal no período: R$ {$total}.";

        if ($insight !== null) {
            $reply .= ' '.$insight;
        }

        $reply .= ' Se quiser, eu posso filtrar por categoria, comparar com o mês passado ou te mostrar o que mais pesou.';

        return $reply;
    }

    private function buildCategoryReply(User $user, Collection $transactions, array $context): string
    {
        $grouped = $transactions->groupBy(fn (Transaction $transaction) => $transaction->category?->name ?? 'Sem categoria');

        if ($grouped->count() > 1) {
            return $this->buildCategoryComparisonReply($transactions, $context);
        }

        $categoryName = (string) $grouped->keys()->first();
        $total = (float) $transactions->sum('amount');
        $count = $transactions->count();
        $periodLabel = $context['period']['label'];
        $advisor = app(FinancialConversationAdvisor::class);

        $reply = sprintf(
            'Em %s, você teve %d %s em %s, somando R$ %s.',
            $periodLabel,
            $count,
            $count === 1 ? 'lançamento' : 'lançamentos',
            $categoryName,
            $this->formatMoney($total)
        );

        $previousPeriod = $this->calculatePreviousPeriodComparison($transactions, $context);
        if ($previousPeriod !== null) {
            $reply .= ' '.$previousPeriod;
        }

        $proactive = $advisor->transactionCategoryInsight($user, $transactions, $context);
        if ($proactive !== null) {
            $reply .= ' '.$proactive;
        }

        $reply .= " Se quiser, eu posso comparar {$categoryName} com outra categoria, listar os lançamentos ou olhar o mês passado.";

        return $reply;
    }

    private function buildCategoryComparisonReply(Collection $transactions, array $context): string
    {
        $byCategory = $transactions->groupBy(fn (Transaction $transaction) => $transaction->category?->name ?? 'Sem categoria')
            ->map(fn (Collection $items) => [
                'name' => (string) ($items->first()->category?->name ?? 'Sem categoria'),
                'total' => (float) $items->sum('amount'),
                'count' => $items->count(),
            ])
            ->sortByDesc('total')
            ->values();

        $lines = $byCategory->map(function (array $row) {
            return sprintf(
                '- %s: R$ %s em %d %s',
                $row['name'],
                $this->formatMoney($row['total']),
                $row['count'],
                $row['count'] === 1 ? 'lançamento' : 'lançamentos'
            );
        })->implode("\n");

        $first = $byCategory->first();
        $second = $byCategory->skip(1)->first();
        $reply = "Comparando seus gastos de {$context['period']['label']}:\n{$lines}";

        if ($first && $second) {
            $difference = $this->formatMoney($first['total'] - $second['total']);
            $reply .= sprintf(
                "\n\nHoje, %s pesa mais do que %s por R$ %s.",
                $first['name'],
                $second['name'],
                $difference
            );
        }

        $reply .= ' Se quiser, eu posso detalhar uma categoria específica ou puxar a comparação do mês passado.';

        return $reply;
    }

    private function calculatePreviousPeriodComparison(Collection $transactions, array $context): ?string
    {
        if ($context['period']['scope'] !== 'current_month' || empty($context['category_names'][0])) {
            return null;
        }

        $previousDate = CarbonImmutable::create($context['period']['year'], $context['period']['month'], 1)->subMonthNoOverflow();
        $categoryName = $context['category_names'][0];
        $categoryId = $transactions->first()?->category_id;

        if (! $categoryId) {
            return null;
        }

        $previousTotal = Transaction::query()
            ->where('user_id', $transactions->first()->user_id)
            ->where('type', $context['type'] ?? 'expense')
            ->where('category_id', $categoryId)
            ->whereYear('date', $previousDate->year)
            ->whereMonth('date', $previousDate->month)
            ->sum('amount');

        if ((float) $previousTotal === 0.0) {
            return "No mês passado, {$categoryName} não teve lançamentos desse tipo.";
        }

        $currentTotal = (float) $transactions->sum('amount');
        $variation = (($currentTotal - (float) $previousTotal) / (float) $previousTotal) * 100;
        $direction = $variation >= 0 ? 'acima' : 'abaixo';

        return sprintf(
            'Isso ficou R$ %s %s, o que representa %s em relação a %s.',
            $this->formatMoney(abs($currentTotal - (float) $previousTotal)),
            $direction,
            $this->formatPercentage(abs($variation)),
            $previousDate->locale('pt_BR')->translatedFormat('F/Y')
        );
    }

    private function buildEntities(array $context): array
    {
        return array_filter([
            'topic' => $context['mode'] === 'category' ? 'expense_category' : 'transactions',
            'transaction_type' => $context['type'],
            'period_scope' => $context['period']['scope'],
            'period_label' => $context['period']['label'],
            'year' => $context['period']['year'],
            'month' => $context['period']['month'],
            'day' => $context['period']['day'],
            'category_name' => count($context['category_names']) === 1 ? $context['category_names'][0] : null,
            'category_names' => count($context['category_names']) > 1 ? $context['category_names'] : null,
            'comparison_mode' => $context['comparison_mode'],
        ], fn ($value) => $value !== null && $value !== [] && $value !== '');
    }

    private function looksLikeCategoryFollowUp(string $message, array $lastEntities): bool
    {
        return ! empty($lastEntities['category_name']) && $this->looksLikeTemporalFollowUp($message);
    }

    private function looksLikeTemporalFollowUp(string $message): bool
    {
        return $this->containsAny($message, ['mes passado', 'mês passado', 'esse mes', 'esse mês', 'este mes', 'este mês', 'hoje', 'ontem']);
    }

    private function labelForType(?string $type): string
    {
        return match ($type) {
            'income' => 'receitas',
            'expense' => 'gastos',
            default => 'transações',
        };
    }

    private function containsAny(string $message, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($message, $this->normalize($needle))) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $converted !== false ? $converted : $value;
    }

    private function formatMoney(float|string $value): string
    {
        return number_format((float) $value, 2, ',', '.');
    }

    private function formatPercentage(float $value): string
    {
        return number_format($value, 0, ',', '.').'%';
    }

    private function resolveRelevantEntities(array $state): array
    {
        $lastEntities = $state['last_entities'] ?? [];

        if (in_array($lastEntities['topic'] ?? null, ['transactions', 'expense_category'], true)) {
            return $lastEntities;
        }

        foreach (($state['recent_contexts'] ?? []) as $context) {
            $entities = $context['entities'] ?? [];

            if (in_array($entities['topic'] ?? null, ['transactions', 'expense_category'], true)) {
                return $entities;
            }
        }

        return $lastEntities;
    }
}
