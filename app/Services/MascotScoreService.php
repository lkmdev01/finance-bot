<?php

namespace App\Services;

use App\Models\MascotAchievementUnlock;
use App\Models\MascotProfile;
use App\Models\SavingsGoalDeposit;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class MascotScoreService
{
    protected function mascotName(): string
    {
        return (string) config('mascot.name', 'Orbita');
    }

    public function sync(User $user): array
    {
        $snapshot = $this->buildSnapshot($user);
        $achievementDefinitions = collect($this->achievementDefinitions());
        $unlockedAchievements = $this->unlockAchievements($user, $snapshot, $achievementDefinitions);

        $scoreBreakdown = $this->calculateScoreBreakdown($snapshot);
        $score = (int) min(100, array_sum($scoreBreakdown));
        $xp = $this->calculateXp($snapshot, $unlockedAchievements->count());
        $level = $this->calculateLevel($xp);
        $recentUnlock = $unlockedAchievements
            ->first(fn (MascotAchievementUnlock $unlock) => $unlock->unlocked_at->gte(now()->subDays(14)));
        $mood = $this->determineMood($score, $snapshot, $recentUnlock);

        $profile = MascotProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'score' => $score,
                'xp' => $xp,
                'level' => $level['level'],
                'current_streak' => $snapshot['current_streak'],
                'best_streak' => $snapshot['best_streak'],
                'badges_unlocked' => $unlockedAchievements->count(),
                'mood' => $mood['key'],
                'last_activity_date' => $snapshot['last_activity_date'],
                'metadata' => [
                    'score_breakdown' => $scoreBreakdown,
                    'stats' => $snapshot,
                    'recent_achievement' => $recentUnlock?->achievement_key,
                ],
            ]
        );

        return [
            'profile' => $profile,
            'score' => $score,
            'score_breakdown' => $scoreBreakdown,
            'xp' => $xp,
            'level' => $level['level'],
            'level_progress' => $level['progress'],
            'xp_in_level' => $level['xp_in_level'],
            'xp_for_next_level' => $level['xp_for_next_level'],
            'current_streak' => $snapshot['current_streak'],
            'best_streak' => $snapshot['best_streak'],
            'badges_unlocked' => $unlockedAchievements->count(),
            'recent_achievement' => $recentUnlock
                ? $this->formatAchievementPayload($recentUnlock->achievement_key, $recentUnlock->metadata ?? [])
                : null,
            'mood' => $mood,
            'focus_area' => $this->determineFocusArea($scoreBreakdown),
            'stats' => $snapshot,
            'achievements' => $achievementDefinitions->map(function (array $definition) use ($unlockedAchievements) {
                $unlock = $unlockedAchievements->firstWhere('achievement_key', $definition['key']);

                return [
                    'key' => $definition['key'],
                    'title' => $definition['title'],
                    'description' => $definition['description'],
                    'icon' => $definition['icon'],
                    'tone' => $definition['tone'],
                    'is_unlocked' => (bool) $unlock,
                    'unlocked_at' => $unlock?->unlocked_at,
                ];
            })->values()->all(),
        ];
    }

    protected function buildSnapshot(User $user): array
    {
        $transactions = $user->transactions()->with('category')->orderBy('date')->get();
        $currentMonth = now()->startOfMonth();
        $currentMonthIncome = (float) $transactions
            ->where('type', 'income')
            ->filter(fn (Transaction $transaction) => $transaction->date && $transaction->date->gte($currentMonth))
            ->sum('amount');

        $currentMonthExpenses = (float) $transactions
            ->where('type', 'expense')
            ->filter(function (Transaction $transaction) use ($currentMonth) {
                if (! $transaction->date || ! $transaction->date->gte($currentMonth)) {
                    return false;
                }

                $metadata = $transaction->metadata ?? [];

                return ! isset($metadata['savings_goal_deposit_id']);
            })
            ->sum('amount');

        $currentMonthSavings = (float) SavingsGoalDeposit::query()
            ->whereHas('savingsGoal', fn ($query) => $query->where('user_id', $user->id))
            ->whereDate('deposit_date', '>=', $currentMonth->toDateString())
            ->sum('amount');

        $goals = $user->savingsGoals()->with('deposits')->get();
        $budgets = $user->budgets()->with('category')->get()->filter(function ($budget) {
            if ($budget->period === 'monthly') {
                return (int) $budget->year === now()->year && (int) $budget->month === now()->month;
            }

            return (int) $budget->year === now()->year;
        });

        $categorizedTransactions = $transactions->filter(fn (Transaction $transaction) => ! is_null($transaction->category_id))->count();
        $transactionDays = $transactions
            ->filter(fn (Transaction $transaction) => ! is_null($transaction->date))
            ->map(fn (Transaction $transaction) => $transaction->date->toDateString())
            ->unique()
            ->sort()
            ->values();

        return [
            'transaction_count' => $transactions->count(),
            'categorized_ratio' => $transactions->count() > 0
                ? round($categorizedTransactions / $transactions->count(), 4)
                : 0.0,
            'current_month_income' => $currentMonthIncome,
            'current_month_expenses' => $currentMonthExpenses,
            'current_month_savings' => $currentMonthSavings,
            'current_streak' => $this->calculateCurrentStreak($transactionDays),
            'best_streak' => $this->calculateBestStreak($transactionDays),
            'last_activity_date' => $transactionDays->last(),
            'active_budgets' => $budgets->count(),
            'budgets_within_limit' => $budgets->filter(fn ($budget) => $budget->spent <= $budget->amount)->count(),
            'budget_health_ratio' => $budgets->count() > 0
                ? round($budgets->filter(fn ($budget) => $budget->spent <= $budget->amount)->count() / $budgets->count(), 4)
                : 0.0,
            'active_goals' => $goals->count(),
            'completed_goals' => $goals->where('is_completed', true)->count(),
            'average_goal_progress' => round((float) $goals->avg('progress_percentage'), 2),
            'has_bank_account' => $user->bankAccounts()->where('is_active', true)->exists(),
            'has_credit_card' => $user->creditCards()->where('is_active', true)->exists(),
            'has_subscription' => $user->subscriptions()->where('is_active', true)->exists(),
        ];
    }

    protected function calculateScoreBreakdown(array $snapshot): array
    {
        return [
            'consistency' => $this->consistencyScore($snapshot),
            'balance' => $this->balanceScore($snapshot),
            'budget' => $this->budgetScore($snapshot),
            'savings' => $this->savingsScore($snapshot),
        ];
    }

    protected function consistencyScore(array $snapshot): int
    {
        if ($snapshot['transaction_count'] === 0) {
            return 0;
        }

        return (int) min(25, 5 + ($snapshot['current_streak'] * 3));
    }

    protected function balanceScore(array $snapshot): int
    {
        $income = $snapshot['current_month_income'];
        $expenses = $snapshot['current_month_expenses'];

        if ($income <= 0 && $expenses <= 0) {
            return 12;
        }

        if ($income <= 0) {
            return 0;
        }

        if ($income >= $expenses) {
            $surplusRatio = min(1, ($income - $expenses) / max($income, 1));

            return (int) round(18 + ($surplusRatio * 7));
        }

        $deficitRatio = max(0, 1 - (($expenses - $income) / max($expenses, 1)));

        return (int) round($deficitRatio * 17);
    }

    protected function budgetScore(array $snapshot): int
    {
        if ($snapshot['active_budgets'] === 0) {
            return $snapshot['transaction_count'] > 0 ? 8 : 0;
        }

        return (int) round($snapshot['budget_health_ratio'] * 25);
    }

    protected function savingsScore(array $snapshot): int
    {
        $income = $snapshot['current_month_income'];
        $savingsRate = $income > 0 ? $snapshot['current_month_savings'] / $income : 0;
        $rateScore = min(18, (int) round(min(1, $savingsRate / 0.2) * 18));
        $goalScore = min(7, (int) round(min(1, $snapshot['average_goal_progress'] / 100) * 7));

        if ($snapshot['active_goals'] === 0 && $snapshot['current_month_savings'] <= 0) {
            return 0;
        }

        return min(25, $rateScore + $goalScore);
    }

    protected function calculateXp(array $snapshot, int $unlockedAchievements): int
    {
        return
            ($snapshot['transaction_count'] * 8) +
            ($snapshot['current_streak'] * 15) +
            ($snapshot['budgets_within_limit'] * 60) +
            ($snapshot['completed_goals'] * 200) +
            ((int) round($snapshot['categorized_ratio'] * 120)) +
            ($snapshot['has_bank_account'] ? 40 : 0) +
            ($snapshot['has_credit_card'] ? 40 : 0) +
            ($snapshot['has_subscription'] ? 30 : 0) +
            min(200, (int) round($snapshot['current_month_savings'])) +
            ($unlockedAchievements * 120);
    }

    protected function calculateLevel(int $xp): array
    {
        $xpPerLevel = 250;
        $level = intdiv($xp, $xpPerLevel) + 1;
        $currentLevelStart = ($level - 1) * $xpPerLevel;
        $nextLevel = $level * $xpPerLevel;
        $xpInLevel = $xp - $currentLevelStart;

        return [
            'level' => $level,
            'xp_in_level' => $xpInLevel,
            'xp_for_next_level' => $xpPerLevel,
            'progress' => (int) round(($xpInLevel / $xpPerLevel) * 100),
            'xp_to_next' => max(0, $nextLevel - $xp),
        ];
    }

    protected function determineMood(int $score, array $snapshot, ?MascotAchievementUnlock $recentUnlock): array
    {
        if ($recentUnlock && $recentUnlock->unlocked_at->gte(now()->subDay())) {
            return [
                'key' => 'celebrating',
                'label' => 'Conquista desbloqueada',
                'headline' => $this->mascotName().' esta comemorando seu progresso.',
                'message' => 'Voce acabou de bater um marco importante. Hora de manter o ritmo.',
                'tone' => 'amber',
            ];
        }

        if ($score >= 80) {
            return [
                'key' => 'on_track',
                'label' => 'No caminho certo',
                'headline' => $this->mascotName().' esta feliz com suas decisoes.',
                'message' => 'Seu dinheiro esta sob controle e seus habitos estao consistentes.',
                'tone' => 'emerald',
            ];
        }

        if (
            $score < 50 ||
            $snapshot['current_month_expenses'] > $snapshot['current_month_income'] ||
            ($snapshot['active_budgets'] > 0 && $snapshot['budget_health_ratio'] < 0.5)
        ) {
            return [
                'key' => 'attention',
                'label' => 'Precisa de atencao',
                'headline' => $this->mascotName().' percebeu sinais de alerta.',
                'message' => 'Vale revisar gastos recentes, reforcar o orcamento e retomar a sequencia.',
                'tone' => 'rose',
            ];
        }

        return [
            'key' => 'steady',
            'label' => 'Em evolucao',
            'headline' => $this->mascotName().' ve progresso e quer te levar mais longe.',
            'message' => 'Voce esta construindo bons habitos. Mais alguns passos e o humor sobe.',
            'tone' => 'sky',
        ];
    }

    protected function achievementDefinitions(): array
    {
        return [
            [
                'key' => 'first_steps',
                'title' => 'Primeiros Passos',
                'description' => 'Registrou sua primeira movimentacao financeira.',
                'icon' => 'seedling',
                'tone' => 'emerald',
                'condition' => fn (array $snapshot) => $snapshot['transaction_count'] >= 1,
            ],
            [
                'key' => 'week_streak',
                'title' => 'Sequencia de 7 Dias',
                'description' => 'Manteve registros por sete dias seguidos.',
                'icon' => 'flame',
                'tone' => 'amber',
                'condition' => fn (array $snapshot) => $snapshot['current_streak'] >= 7,
            ],
            [
                'key' => 'budget_guardian',
                'title' => 'Guardiao do Orcamento',
                'description' => 'Manteve todos os orcamentos ativos dentro do limite.',
                'icon' => 'shield',
                'tone' => 'sky',
                'condition' => fn (array $snapshot) => $snapshot['active_budgets'] > 0 && $snapshot['budget_health_ratio'] >= 1,
            ],
            [
                'key' => 'savings_builder',
                'title' => 'Construtor de Reserva',
                'description' => 'Comecou a formar reserva e metas de economia.',
                'icon' => 'banknotes',
                'tone' => 'amber',
                'condition' => fn (array $snapshot) => $snapshot['current_month_savings'] >= 100 || $snapshot['average_goal_progress'] >= 25,
            ],
            [
                'key' => 'goal_crusher',
                'title' => 'Meta Concluida',
                'description' => 'Fechou uma meta financeira com sucesso.',
                'icon' => 'trophy',
                'tone' => 'violet',
                'condition' => fn (array $snapshot) => $snapshot['completed_goals'] >= 1,
            ],
            [
                'key' => 'money_rhythm',
                'title' => 'Ritmo Saudavel',
                'description' => 'Fechou o mes com receitas maiores do que despesas.',
                'icon' => 'chart-bar',
                'tone' => 'emerald',
                'condition' => fn (array $snapshot) => $snapshot['current_month_income'] > 0 && $snapshot['current_month_income'] >= $snapshot['current_month_expenses'],
            ],
            [
                'key' => 'organized_wallet',
                'title' => 'Carteira Organizada',
                'description' => 'Organizou contas, cartoes e transacoes categorizadas.',
                'icon' => 'wallet',
                'tone' => 'sky',
                'condition' => fn (array $snapshot) => $snapshot['has_bank_account'] && $snapshot['has_credit_card'] && $snapshot['categorized_ratio'] >= 0.8,
            ],
        ];
    }

    protected function unlockAchievements(User $user, array $snapshot, Collection $definitions): Collection
    {
        $eligible = $definitions->filter(fn (array $definition) => ($definition['condition'])($snapshot));

        foreach ($eligible as $definition) {
            MascotAchievementUnlock::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'achievement_key' => $definition['key'],
                ],
                [
                    'unlocked_at' => now(),
                    'metadata' => [
                        'title' => $definition['title'],
                        'description' => $definition['description'],
                        'icon' => $definition['icon'],
                        'tone' => $definition['tone'],
                    ],
                ]
            );
        }

        return $user->mascotAchievementUnlocks()->orderByDesc('unlocked_at')->get();
    }

    protected function formatAchievementPayload(string $key, array $metadata = []): array
    {
        $definition = collect($this->achievementDefinitions())->firstWhere('key', $key);

        return [
            'key' => $key,
            'title' => $metadata['title'] ?? $definition['title'] ?? $key,
            'description' => $metadata['description'] ?? $definition['description'] ?? '',
            'icon' => $metadata['icon'] ?? $definition['icon'] ?? 'trophy',
            'tone' => $metadata['tone'] ?? $definition['tone'] ?? 'amber',
        ];
    }

    protected function determineFocusArea(array $scoreBreakdown): array
    {
        $areas = [
            'consistency' => [
                'title' => 'Sequencia diaria',
                'description' => 'Registrar movimentacoes em dias seguidos fortalece a memoria financeira e acelera o XP.',
            ],
            'balance' => [
                'title' => 'Equilibrio do mes',
                'description' => 'Ajuste o ritmo do mes para manter receitas acima das despesas.',
            ],
            'budget' => [
                'title' => 'Disciplina de orcamento',
                'description' => 'Orcamentos ativos dentro do limite fazem o humor do '.$this->mascotName().' subir rapido.',
            ],
            'savings' => [
                'title' => 'Reserva e metas',
                'description' => 'Separar um valor fixo para economias melhora sua saude financeira.',
            ],
        ];

        $weakestKey = collect($scoreBreakdown)->sort()->keys()->first();

        return [
            'key' => $weakestKey,
            'score' => $scoreBreakdown[$weakestKey] ?? 0,
            'title' => $areas[$weakestKey]['title'] ?? 'Foco atual',
            'description' => $areas[$weakestKey]['description'] ?? 'Pequenos ajustes neste ponto ja melhoram sua pontuacao.',
        ];
    }

    protected function calculateCurrentStreak(Collection $transactionDays): int
    {
        if ($transactionDays->isEmpty()) {
            return 0;
        }

        $dates = $transactionDays
            ->map(fn (string $date) => Carbon::parse($date)->startOfDay())
            ->sortDesc()
            ->values();

        if (! $dates->first()->isToday() && ! $dates->first()->isYesterday()) {
            return 0;
        }

        $streak = 1;

        for ($index = 1; $index < $dates->count(); $index++) {
            $expected = $dates[$index - 1]->copy()->subDay();

            if (! $dates[$index]->isSameDay($expected)) {
                break;
            }

            $streak++;
        }

        return $streak;
    }

    protected function calculateBestStreak(Collection $transactionDays): int
    {
        if ($transactionDays->isEmpty()) {
            return 0;
        }

        $dates = $transactionDays
            ->map(fn (string $date) => Carbon::parse($date)->startOfDay())
            ->sort()
            ->values();

        $best = 1;
        $current = 1;

        for ($index = 1; $index < $dates->count(); $index++) {
            $expected = $dates[$index - 1]->copy()->addDay();

            if ($dates[$index]->isSameDay($expected)) {
                $current++;
                $best = max($best, $current);
                continue;
            }

            $current = 1;
        }

        return $best;
    }
}
