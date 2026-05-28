<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

new class extends Component
{
    public string $period = 'monthly'; // 'monthly' ou 'yearly'

    public ?string $selectedMonth = null;
    public bool $showOnboardingTutorial = false;
    public int $onboardingStep = 0;
    public string $onboardingPhoneNumber = '';
    
    protected function user(): \App\Models\User
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        return $user;
    }

    public function getExceededBudgets(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->user()->budgets()
            ->with('category')
            ->get()
            ->filter(function ($budget) {
                return $budget->spent > $budget->amount;
            });
    }

    public function with(): array
    {
        return [
            'title' => 'Planejamento de '.$this->user()->name,
            'exceededBudgets' => $this->getExceededBudgets(),
            'mascotSummary' => app(\App\Services\MascotScoreService::class)->sync($this->user()),
        ];
    }

    public function mount(): void
    {
        $this->selectedMonth = now()->format('Y-m');
        $this->onboardingPhoneNumber = $this->user()->phone_number ?? '';
        $this->showOnboardingTutorial = false;
    }

    public function startOnboardingTutorial(): void
    {
        $this->onboardingStep = blank($this->onboardingPhoneNumber) ? 1 : 2;
    }

    public function dismissOnboardingTutorial(): void
    {
        $this->user()->forceFill([
            'onboarding_tutorial_seen_at' => now(),
        ])->save();

        $this->showOnboardingTutorial = false;
        $this->onboardingStep = 0;
    }

    public function saveOnboardingPhoneNumber(): void
    {
        $service = app(\App\Services\PhoneNumberService::class);
        $phoneNumber = $service->formatForStorage($this->onboardingPhoneNumber ?? '');

        if ($phoneNumber === '') {
            $phoneNumber = null;
        }

        $this->validate([
            'onboardingPhoneNumber' => [
                'required',
                'string',
                'max:20',
                'regex:/^[0-9+\-\s()]+$/',
                function ($attribute, $value, $fail) use ($phoneNumber) {
                    $exists = DB::table('users')
                        ->where('phone_number', $phoneNumber)
                        ->where('id', '!=', $this->user()->id)
                        ->exists();

                    if ($exists) {
                        $fail('Esse número já está vinculado a outra conta.');
                    }
                },
            ],
        ], [
            'onboardingPhoneNumber.required' => 'Preencha seu número para continuar.',
            'onboardingPhoneNumber.regex' => 'Use um número válido com DDD.',
        ]);

        $user = $this->user();
        $user->phone_number = $phoneNumber;
        $user->save();

        $this->onboardingPhoneNumber = $phoneNumber;
        $this->onboardingStep = 2;
    }

    public function updatedPeriod(): void
    {
        $this->selectedMonth = now()->format('Y-m');
    }

    public function getTotalIncome(): float
    {
        $query = $this->user()->transactions()->where('type', 'income');

        if ($this->period === 'monthly') {
            [$year, $month] = explode('-', $this->selectedMonth);
            $query->whereYear('date', $year)->whereMonth('date', $month);
        } else {
            $query->whereYear('date', now()->year);
        }

        return (float) $query->sum('amount');
    }

    public function getTotalExpenses(): float
    {
        $query = $this->user()->transactions()->where('type', 'expense');

        if ($this->period === 'monthly') {
            [$year, $month] = explode('-', $this->selectedMonth);
            $query->whereYear('date', $year)->whereMonth('date', $month);
        } else {
            $query->whereYear('date', now()->year);
        }

        return (float) $query->sum('amount');
    }

    public function getTotalSavingsDeposits(): float
    {
        return (float) $this->user()->savingsGoals()
            ->with('deposits')
            ->get()
            ->sum(fn($goal) => $goal->deposits->sum('amount'));
    }

    public function getTotalIncomeAllTime(): float
    {
        return (float) $this->user()->transactions()
            ->where('type', 'income')
            ->sum('amount');
    }

    public function getTotalExpensesAllTime(): float
    {
        // Excluir transacoes de deposito em metas (ja sao contadas separadamente)
        $allExpenses = $this->user()->transactions()
            ->where('type', 'expense')
            ->get();
        
        // Filtrar transacoes que nao sao depositos em metas
        $expensesWithoutSavings = $allExpenses->filter(function ($transaction) {
            $metadata = $transaction->metadata ?? [];
            return !isset($metadata['savings_goal_deposit_id']);
        });
        
        return (float) $expensesWithoutSavings->sum('amount');
    }

    public function getAvailableBalance(): float
    {
        // Saldo disponivel considera todas as transacoes, nao apenas do periodo
        // Depositos em metas sao deduzidos separadamente (nao contam como despesas normais)
        return $this->getTotalIncomeAllTime() - $this->getTotalExpensesAllTime() - $this->getTotalSavingsDeposits();
    }

    public function getCreditCardsSummary(): array
    {
        try {
            $user = $this->user();

            $cards = $user->creditCards()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'credit_limit', 'opening_balance', 'closing_day', 'due_day']);

            if ($cards->isEmpty()) {
                return [];
            }

            $deltas = $user->transactions()
                ->whereIn('credit_card_id', $cards->pluck('id')->all())
                ->selectRaw("credit_card_id, SUM(CASE WHEN type = 'expense' THEN amount ELSE -amount END) as delta")
                ->groupBy('credit_card_id')
                ->pluck('delta', 'credit_card_id');

            return $cards->map(function ($card) use ($deltas) {
                $delta = (float) ($deltas[$card->id] ?? 0);
                $currentBalance = round((float) $card->opening_balance + $delta, 2);
                $available = round((float) $card->credit_limit - $currentBalance, 2);
                $usagePct = (float) $card->credit_limit > 0 ? round(($currentBalance / (float) $card->credit_limit) * 100, 1) : 0.0;

                return [
                    'id' => $card->id,
                    'name' => $card->name,
                    'credit_limit' => (float) $card->credit_limit,
                    'current_balance' => $currentBalance,
                    'available_limit' => $available,
                    'usage_pct' => $usagePct,
                    'closing_day' => $card->closing_day,
                    'due_day' => $card->due_day,
                ];
            })->values()->toArray();
        } catch (\Throwable $e) {
            Log::error('Dashboard: falha ao calcular resumo de cartoes', [
                'user_id' => $this->user()->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function getBankAccountsSummary(): array
    {
        try {
            $user = $this->user();

            $accounts = $user->bankAccounts()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'type', 'opening_balance', 'color']);

            if ($accounts->isEmpty()) {
                return [];
            }

            $deltas = $user->transactions()
                ->whereIn('bank_account_id', $accounts->pluck('id')->all())
                ->selectRaw("bank_account_id, SUM(CASE WHEN type = 'income' THEN amount ELSE -amount END) as delta")
                ->groupBy('bank_account_id')
                ->pluck('delta', 'bank_account_id');

            return $accounts->map(function ($account) use ($deltas) {
                $delta = (float) ($deltas[$account->id] ?? 0);
                $currentBalance = round((float) $account->opening_balance + $delta, 2);

                return [
                    'id' => $account->id,
                    'name' => $account->name,
                    'type' => $account->type,
                    'color' => $account->color,
                    'current_balance' => $currentBalance,
                ];
            })->values()->toArray();
        } catch (\Throwable $e) {
            Log::error('Dashboard: falha ao calcular resumo de contas', [
                'user_id' => $this->user()->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function getBankAccountsTotals(): array
    {
        $accounts = $this->getBankAccountsSummary();

        $total = 0.0;
        $negative = [];

        foreach ($accounts as $account) {
            $balance = (float) ($account['current_balance'] ?? 0);
            $total += $balance;

            if ($balance < 0) {
                $negative[] = [
                    'id' => $account['id'] ?? null,
                    'name' => $account['name'] ?? 'Conta',
                    'balance' => $balance,
                ];
            }
        }

        usort($negative, fn ($a, $b) => ($a['balance'] ?? 0) <=> ($b['balance'] ?? 0));

        return [
            'total_balance' => round($total, 2),
            'negative_accounts' => $negative,
        ];
    }

    public function getCreditCardsTotals(): array
    {
        $cards = $this->getCreditCardsSummary();

        $totalLimit = 0.0;
        $totalUsed = 0.0;
        $tight = [];

        foreach ($cards as $card) {
            $limit = (float) ($card['credit_limit'] ?? 0);
            $used = (float) ($card['current_balance'] ?? 0);
            $usage = (float) ($card['usage_pct'] ?? 0);

            $totalLimit += $limit;
            $totalUsed += $used;

            if ($usage >= 80) {
                $tight[] = [
                    'id' => $card['id'] ?? null,
                    'name' => $card['name'] ?? 'Cartao',
                    'usage_pct' => $usage,
                    'available_limit' => (float) ($card['available_limit'] ?? 0),
                ];
            }
        }

        usort($tight, fn ($a, $b) => ($b['usage_pct'] ?? 0) <=> ($a['usage_pct'] ?? 0));

        return [
            'total_limit' => round($totalLimit, 2),
            'total_used' => round($totalUsed, 2),
            'total_available' => round($totalLimit - $totalUsed, 2),
            'tight_cards' => $tight,
        ];
    }

    public function getUpcomingFinancialEvents(int $days = 30): array
    {
        $user = $this->user();
        $today = now()->startOfDay();
        $until = now()->addDays($days)->endOfDay();

        $items = [];

        $subscriptions = $user->subscriptions()
            ->where('is_active', true)
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '<=', $until->toDateString())
            ->orderBy('next_due_date')
            ->limit(20)
            ->get(['id', 'name', 'amount', 'next_due_date', 'billing_cycle', 'bank_account_id', 'credit_card_id']);

        foreach ($subscriptions as $sub) {
            if (! $sub->next_due_date) {
                continue;
            }

            $date = $sub->next_due_date->copy()->startOfDay();
            $items[] = [
                'kind' => 'subscription',
                'id' => $sub->id,
                'label' => $sub->name,
                'amount' => (float) $sub->amount,
                'date' => $date,
                'is_overdue' => $date->lt($today),
            ];
        }

        $cards = $this->getCreditCardsSummary();
        foreach ($cards as $card) {
            $dueDay = (int) ($card['due_day'] ?? 0);
            if ($dueDay <= 0) {
                continue;
            }

            $base = \Carbon\Carbon::create(now()->year, now()->month, 1)->startOfMonth();
            $dueDate = $base->copy()->day(min($dueDay, $base->daysInMonth))->startOfDay();
            if ($dueDate->lt($today)) {
                $next = $base->copy()->addMonth();
                $dueDate = $next->day(min($dueDay, $next->daysInMonth))->startOfDay();
            }

            if ($dueDate->gt($until)) {
                continue;
            }

            $items[] = [
                'kind' => 'credit_card',
                'id' => $card['id'] ?? null,
                'label' => $card['name'] ?? 'Cartao',
                'amount' => (float) ($card['current_balance'] ?? 0),
                'date' => $dueDate,
                'is_overdue' => false,
                'usage_pct' => (float) ($card['usage_pct'] ?? 0),
            ];
        }

        usort($items, fn ($a, $b) => ($a['date'] ?? $today) <=> ($b['date'] ?? $today));

        return array_slice($items, 0, 8);
    }

    public function getDashboardInsights(): array
    {
        $insights = [];

        $bankTotals = $this->getBankAccountsTotals();
        if (! empty($bankTotals['negative_accounts'][0])) {
            $acc = $bankTotals['negative_accounts'][0];
            $insights[] = [
                'tone' => 'warning',
                'text' => sprintf('Conta %s esta negativa: R$ %s.', (string) ($acc['name'] ?? 'Conta'), number_format(abs((float) ($acc['balance'] ?? 0)), 2, ',', '.')),
                'cta' => route('bank-accounts.index'),
                'cta_label' => 'Ver contas',
            ];
        }

        $cardTotals = $this->getCreditCardsTotals();
        if (! empty($cardTotals['tight_cards'][0])) {
            $card = $cardTotals['tight_cards'][0];
            $insights[] = [
                'tone' => 'warning',
                'text' => sprintf('Cartao %s esta com %s%% do limite usado.', (string) ($card['name'] ?? 'Cartao'), number_format((float) ($card['usage_pct'] ?? 0), 1, ',', '.')),
                'cta' => route('credit-cards.index'),
                'cta_label' => 'Ver cartoes',
            ];
        }

        $expensesByCategory = $this->getExpensesByCategory();
        if (! empty($expensesByCategory[0]['category'])) {
            $top = $expensesByCategory[0];
            $pct = (float) ($top['percentage'] ?? 0);
            if ($pct >= 40) {
                $insights[] = [
                    'tone' => 'info',
                    'text' => sprintf('%s concentra %s%% das despesas do periodo.', (string) ($top['category'] ?? 'Uma categoria'), number_format($pct, 1, ',', '.')),
                    'cta' => route('reports.index'),
                    'cta_label' => 'Abrir relatorio',
                ];
            }
        }

        if ($insights === []) {
            $insights[] = [
                'tone' => 'ok',
                'text' => 'Sem alertas hoje. Quer que eu gere um relatorio rapido do periodo?',
                'cta' => route('reports.index'),
                'cta_label' => 'Ver relatorios',
            ];
        }

        return array_slice($insights, 0, 3);
    }

    public function getExpensesByCategory(): array
    {
        $user = $this->user();
        $query = $user->transactions()
            ->where('type', 'expense')
            ->whereNotNull('category_id');

        if ($this->period === 'monthly') {
            [$year, $month] = explode('-', $this->selectedMonth);
            $query->whereYear('date', $year)->whereMonth('date', $month);
        } else {
            $query->whereYear('date', now()->year);
        }

        $expenses = $query->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->with('category')
            ->get();

        $total = $expenses->sum('total');

        return $expenses->map(function ($expense) use ($total) {
            return [
                'category' => $expense->category->name,
                'icon' => $this->normalizeCategoryIcon($expense->category->icon ?? null),
                'color' => $expense->category->color ?? '#95A5A6',
                'amount' => (float) $expense->total,
                'percentage' => $total > 0 ? round(($expense->total / $total) * 100, 1) : 0,
            ];
        })->sortByDesc('amount')->values()->toArray();
    }

    public function getRecentTransactions(): \Illuminate\Database\Eloquent\Collection
    {
        $user = $this->user();
        $query = $user->transactions()
            ->with('category')
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->limit(10);

        if ($this->period === 'monthly') {
            [$year, $month] = explode('-', $this->selectedMonth);
            $query->whereYear('date', $year)->whereMonth('date', $month);
        } else {
            $query->whereYear('date', now()->year);
        }

        return $query->get();
    }
    protected function normalizeCategoryIcon(?string $icon): string
    {
        $icon = trim((string) $icon);
        if ($icon === '') {
            return html_entity_decode('&#128230;', ENT_QUOTES, 'UTF-8');
        }

        if (str_contains($icon, 'Ã') || str_contains($icon, 'Â') || str_contains($icon, 'â') || str_contains($icon, '�')) {
            return html_entity_decode('&#128230;', ENT_QUOTES, 'UTF-8');
        }

        return $icon;
    }
    public function getPreviousMonthIncome(): float
    {
        if ($this->period !== 'monthly') {
            return 0;
        }

        [$year, $month] = explode('-', $this->selectedMonth);
        $previousMonth = \Carbon\Carbon::create($year, $month, 1)->subMonth();

        return (float) $this->user()->transactions()
            ->where('type', 'income')
            ->whereYear('date', $previousMonth->year)
            ->whereMonth('date', $previousMonth->month)
            ->sum('amount');
    }

    public function getPreviousMonthExpenses(): float
    {
        if ($this->period !== 'monthly') {
            return 0;
        }

        [$year, $month] = explode('-', $this->selectedMonth);
        $previousMonth = \Carbon\Carbon::create($year, $month, 1)->subMonth();

        return (float) $this->user()->transactions()
            ->where('type', 'expense')
            ->whereYear('date', $previousMonth->year)
            ->whereMonth('date', $previousMonth->month)
            ->sum('amount');
    }

    public function getIncomeVariation(): float
    {
        $current = $this->getTotalIncome();
        $previous = $this->getPreviousMonthIncome();
        
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }
        
        return (($current - $previous) / $previous) * 100;
    }

    public function getExpensesVariation(): float
    {
        $current = $this->getTotalExpenses();
        $previous = $this->getPreviousMonthExpenses();
        
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }
        
        return (($current - $previous) / $previous) * 100;
    }

    public function getDailyTransactions(): array
    {
        $user = $this->user();
        $query = $user->transactions();

        if ($this->period === 'monthly') {
            [$year, $month] = explode('-', $this->selectedMonth);
            $query->whereYear('date', $year)->whereMonth('date', $month);
        } else {
            $query->whereYear('date', now()->year);
        }

        $transactions = $query->get();

        $days = [];
        $startDate = $this->period === 'monthly' 
            ? \Carbon\Carbon::createFromFormat('Y-m', $this->selectedMonth)->startOfMonth()
            : now()->startOfYear();
        $endDate = $this->period === 'monthly'
            ? \Carbon\Carbon::createFromFormat('Y-m', $this->selectedMonth)->endOfMonth()
            : now()->endOfYear();

        $currentDate = $startDate->copy();
        while ($currentDate <= $endDate) {
            $dayIncome = $transactions
                ->filter(fn($t) => $t->type === 'income' && $t->date->isSameDay($currentDate))
                ->sum('amount');
            
            $dayExpense = $transactions
                ->filter(fn($t) => $t->type === 'expense' && $t->date->isSameDay($currentDate))
                ->sum('amount');

            $days[] = [
                'date' => $currentDate->format('d/m'),
                'day' => $currentDate->day,
                'income' => (float) $dayIncome,
                'expense' => (float) $dayExpense,
                'isToday' => $currentDate->isToday(),
            ];

            $currentDate->addDay();
        }

        return $days;
    }

    public function getMonthlyEvolution(): array
    {
        $user = $this->user();
        $months = [];
        
        // Ultimos 12 meses
        for ($i = 11; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $monthStart = $date->copy()->startOfMonth();
            $monthEnd = $date->copy()->endOfMonth();
            
            $income = (float) $user->transactions()
                ->where('type', 'income')
                ->whereBetween('date', [$monthStart, $monthEnd])
                ->sum('amount');
            
            $expense = (float) $user->transactions()
                ->where('type', 'expense')
                ->whereBetween('date', [$monthStart, $monthEnd])
                ->sum('amount');
            
            $months[] = [
                'month' => $date->format('M/Y'),
                'monthName' => $date->locale('pt_BR')->monthName,
                'year' => $date->year,
                'monthNumber' => $date->month,
                'income' => $income,
                'expense' => $expense,
                'balance' => $income - $expense,
                'isCurrent' => $date->isCurrentMonth() && $date->isCurrentYear(),
            ];
        }
        
        return $months;
    }

    public function getYearlyEvolution(): array
    {
        $user = $this->user();
        $years = [];
        
        // Ultimos 5 anos
        for ($i = 4; $i >= 0; $i--) {
            $year = now()->year - $i;
            $yearStart = \Carbon\Carbon::create($year, 1, 1)->startOfYear();
            $yearEnd = \Carbon\Carbon::create($year, 12, 31)->endOfYear();
            
            $income = (float) $user->transactions()
                ->where('type', 'income')
                ->whereBetween('date', [$yearStart, $yearEnd])
                ->sum('amount');
            
            $expense = (float) $user->transactions()
                ->where('type', 'expense')
                ->whereBetween('date', [$yearStart, $yearEnd])
                ->sum('amount');
            
            $years[] = [
                'year' => $year,
                'income' => $income,
                'expense' => $expense,
                'balance' => $income - $expense,
            ];
        }
        
        return $years;
    }
}; ?>

@php
    $tutorialContactNumber = config('whatsapp.tutorial.contact_number');
    $tutorialContactDigits = preg_replace('/\D+/', '', (string) $tutorialContactNumber);
    $tutorialContactLabel = config('whatsapp.tutorial.contact_label', 'WhatsApp oficial do InovaFinance');
    $tutorialPrefilledMessage = config('whatsapp.tutorial.prefilled_message', 'Oi! Acabei de entrar no InovaFinance e quero testar o robô.');
    $tutorialWhatsappUrl = $tutorialContactDigits
        ? 'https://wa.me/'.$tutorialContactDigits.'?text='.urlencode($tutorialPrefilledMessage)
        : null;
@endphp

<div x-data="{ notificationsOpen: false }">
    @if($showOnboardingTutorial)
        <section class="min-h-screen bg-[radial-gradient(circle_at_top,_rgba(16,185,129,0.16),_transparent_32%),linear-gradient(180deg,#030712_0%,#081120_48%,#030712_100%)] text-white">
            <div class="mx-auto flex min-h-screen max-w-7xl items-center px-4 py-8 sm:px-6 lg:px-8">
                <div class="grid w-full gap-8 lg:grid-cols-[1.08fr_0.92fr]">
                    <div class="relative overflow-hidden rounded-[2rem] border border-white/10 bg-slate-950/75 shadow-[0_32px_120px_rgba(2,6,23,0.65)] backdrop-blur">
                        <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_left,_rgba(56,189,248,0.14),_transparent_30%),radial-gradient(circle_at_bottom_right,_rgba(16,185,129,0.16),_transparent_32%)]"></div>
                        <div class="relative p-6 sm:p-8 lg:p-10">
                            <div class="flex items-start justify-between gap-4">
                                <div class="max-w-2xl">
                                    <span class="inline-flex items-center rounded-full border border-emerald-400/20 bg-emerald-400/10 px-4 py-2 text-xs font-semibold uppercase tracking-[0.28em] text-emerald-200">
                                        Primeiros passos
                                    </span>
                                    <h1 class="mt-5 text-4xl font-black tracking-tight text-white sm:text-5xl">
                                        Configure uma vez.
                                        <span class="block text-slate-300">Depois é só falar no WhatsApp.</span>
                                    </h1>
                                    <p class="mt-5 max-w-xl text-base leading-8 text-slate-300">
                                        Antes de entrar no painel, vamos conectar seu número e te mostrar exatamente como o InovaFinance registra gastos, receitas e consultas por mensagem.
                                    </p>
                                </div>

                                <button
                                    type="button"
                                    wire:click="dismissOnboardingTutorial"
                                    class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-white/10 bg-white/5 text-slate-300 transition hover:bg-white/10 hover:text-white"
                                    aria-label="Fechar tutorial"
                                >
                                    ×
                                </button>
                            </div>

                            <div class="mt-8 flex items-center gap-3 text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">
                                <span class="inline-flex h-9 w-9 items-center justify-center rounded-full {{ $onboardingStep === 0 ? 'bg-white text-slate-950' : 'border border-white/15 bg-white/5 text-slate-300' }}">1</span>
                                <span class="h-px flex-1 bg-white/10"></span>
                                <span class="inline-flex h-9 w-9 items-center justify-center rounded-full {{ $onboardingStep === 1 ? 'bg-white text-slate-950' : 'border border-white/15 bg-white/5 text-slate-300' }}">2</span>
                                <span class="h-px flex-1 bg-white/10"></span>
                                <span class="inline-flex h-9 w-9 items-center justify-center rounded-full {{ $onboardingStep === 2 ? 'bg-white text-slate-950' : 'border border-white/15 bg-white/5 text-slate-300' }}">3</span>
                            </div>

                            @if($onboardingStep === 0)
                                <div class="mt-8 grid gap-5 lg:grid-cols-[1fr_0.92fr]">
                                    <div class="space-y-5">
                                        <div class="grid gap-4 sm:grid-cols-3">
                                            <div class="rounded-3xl border border-white/10 bg-white/5 p-5">
                                                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-emerald-200">Em menos de 2 min</p>
                                                <p class="mt-3 text-sm leading-6 text-slate-300">Você configura uma vez e já pode testar imediatamente.</p>
                                            </div>
                                            <div class="rounded-3xl border border-white/10 bg-white/5 p-5">
                                                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-sky-200">Reconhecimento automático</p>
                                                <p class="mt-3 text-sm leading-6 text-slate-300">Seu número fica vinculado para o robô saber que a mensagem é sua.</p>
                                            </div>
                                            <div class="rounded-3xl border border-white/10 bg-white/5 p-5">
                                                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-amber-200">Tudo em conversa</p>
                                                <p class="mt-3 text-sm leading-6 text-slate-300">Gasto, receita, saldo e relatórios viram comandos simples no WhatsApp.</p>
                                            </div>
                                        </div>

                                        <div class="rounded-[1.8rem] border border-white/10 bg-slate-950/60 p-6">
                                            <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">O que vai acontecer</p>
                                            <div class="mt-5 space-y-5">
                                                <div class="flex gap-4">
                                                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-sky-400/15 text-sm font-bold text-sky-200">1</div>
                                                    <div>
                                                        <p class="text-lg font-bold text-white">Salve seu número</p>
                                                        <p class="mt-1 text-sm leading-6 text-slate-300">É isso que permite ao sistema reconhecer você quando a mensagem chegar.</p>
                                                    </div>
                                                </div>
                                                <div class="flex gap-4">
                                                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-emerald-400/15 text-sm font-bold text-emerald-200">2</div>
                                                    <div>
                                                        <p class="text-lg font-bold text-white">Abra a conversa oficial</p>
                                                        <p class="mt-1 text-sm leading-6 text-slate-300">No último passo já tem botão pronto para abrir o WhatsApp com a primeira mensagem.</p>
                                                    </div>
                                                </div>
                                                <div class="flex gap-4">
                                                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-amber-400/15 text-sm font-bold text-amber-200">3</div>
                                                    <div>
                                                        <p class="text-lg font-bold text-white">Comece com um teste simples</p>
                                                        <p class="mt-1 text-sm leading-6 text-slate-300">Exemplos: “gastei 32 no Uber”, “recebi 420” ou “qual é o meu saldo?”.</p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="flex flex-wrap gap-3">
                                            <flux:button type="button" variant="primary" wire:click="startOnboardingTutorial">
                                                Começar configuração
                                            </flux:button>
                                            <flux:button type="button" variant="ghost" wire:click="dismissOnboardingTutorial">
                                                Pular por agora
                                            </flux:button>
                                        </div>
                                    </div>

                                    <div class="rounded-[1.8rem] border border-white/10 bg-[#08101d] p-5 shadow-2xl">
                                        <div class="flex items-center justify-between gap-4 border-b border-white/10 pb-4">
                                            <div>
                                                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-emerald-200">Prévia real</p>
                                                <p class="mt-2 text-lg font-bold text-white">Seu primeiro teste</p>
                                            </div>
                                            <div class="rounded-2xl border border-emerald-400/20 bg-emerald-400/10 px-3 py-2 text-right">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-emerald-200">Etapa final</p>
                                                <p class="mt-1 text-sm font-bold text-white">1 clique para abrir</p>
                                            </div>
                                        </div>

                                        <div class="mt-5 space-y-4">
                                            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                                                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Número oficial</p>
                                                <p class="mt-2 text-base font-bold text-white">{{ $tutorialContactNumber ?: '+55 13 97605-4715' }}</p>
                                                <p class="mt-2 text-sm leading-6 text-slate-300">Assim que você terminar, abrimos essa conversa para você em um clique.</p>
                                            </div>

                                            <div class="rounded-[1.6rem] border border-white/10 bg-slate-950/80 p-4">
                                                <div class="flex items-center gap-3 border-b border-white/10 pb-4">
                                                    <div class="flex h-11 w-11 items-center justify-center rounded-full bg-emerald-400/15 text-xl">💬</div>
                                                    <div>
                                                        <p class="font-semibold text-white">{{ $tutorialContactLabel }}</p>
                                                        <p class="text-xs text-emerald-200">pronto para sua primeira mensagem</p>
                                                    </div>
                                                </div>

                                                <div class="space-y-3 px-1 py-4">
                                                    <div class="ml-auto max-w-[82%] rounded-2xl rounded-br-md bg-emerald-500 px-4 py-3 text-sm font-medium text-slate-950 shadow-lg">
                                                        Oi! Quero testar.
                                                    </div>
                                                    <div class="max-w-[88%] rounded-2xl rounded-bl-md bg-white/8 px-4 py-3 text-sm leading-6 text-slate-100">
                                                        Perfeito. Me manda algo como “gastei 20 no almoço”, “recebi 500” ou “qual é o meu saldo?”.
                                                    </div>
                                                    <div class="ml-auto max-w-[82%] rounded-2xl rounded-br-md bg-emerald-500 px-4 py-3 text-sm font-medium text-slate-950 shadow-lg">
                                                        Gastei 32 no Uber
                                                    </div>
                                                    <div class="max-w-[88%] rounded-2xl rounded-bl-md bg-white/8 px-4 py-3 text-sm leading-6 text-slate-100">
                                                        ✅ Registrei R$ 32,00 em Transporte (Uber).
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @elseif($onboardingStep === 1)
                                <div class="mt-8 grid gap-6 lg:grid-cols-[1fr_0.9fr]">
                                    <div class="rounded-[1.8rem] border border-white/10 bg-slate-950/60 p-6 sm:p-7">
                                        <p class="text-sm font-semibold uppercase tracking-[0.24em] text-sky-200">Passo 1 de 2</p>
                                        <h2 class="mt-3 text-3xl font-black text-white">Qual número vai conversar com o robô?</h2>
                                        <p class="mt-3 max-w-xl text-sm leading-7 text-slate-300 sm:text-base">
                                            Preencha seu WhatsApp com DDD. É esse número que vamos usar para reconhecer suas mensagens automaticamente.
                                        </p>

                                        <form wire:submit="saveOnboardingPhoneNumber" class="mt-6 space-y-5">
                                            <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
                                                <flux:input
                                                    wire:model="onboardingPhoneNumber"
                                                    label="Seu número de WhatsApp"
                                                    type="tel"
                                                    placeholder="(11) 99999-9999"
                                                    hint="Pode digitar só DDD + número. O sistema formata para você."
                                                />
                                            </div>

                                            <div class="flex flex-wrap gap-3">
                                                <flux:button type="submit" variant="primary">
                                                    Salvar número e continuar
                                                </flux:button>
                                                <flux:button type="button" variant="ghost" wire:click="$set('onboardingStep', 0)">
                                                    Voltar
                                                </flux:button>
                                                <a href="{{ route('whatsapp.settings') }}" class="inline-flex items-center justify-center rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-medium text-slate-200 transition hover:bg-white/10">
                                                    Abrir página completa
                                                </a>
                                            </div>
                                        </form>
                                    </div>

                                    <div class="rounded-[1.8rem] border border-white/10 bg-[#08101d] p-6">
                                        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Por que isso importa</p>
                                        <div class="mt-5 space-y-4">
                                            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                                                <p class="font-semibold text-white">Reconhecimento automático</p>
                                                <p class="mt-2 text-sm leading-6 text-slate-300">Depois que o número estiver salvo, o sistema bate a mensagem com a sua conta sem você precisar fazer mais nada.</p>
                                            </div>
                                            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                                                <p class="font-semibold text-white">Primeiro teste sem fricção</p>
                                                <p class="mt-2 text-sm leading-6 text-slate-300">Seu primeiro teste pode ser algo simples como “gastei 20 no almoço” ou “recebi 500”.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @else
                                <div class="mt-8 grid gap-6 lg:grid-cols-[1fr_0.95fr]">
                                    <div class="rounded-[1.8rem] border border-white/10 bg-slate-950/60 p-6 sm:p-7">
                                        <p class="text-sm font-semibold uppercase tracking-[0.24em] text-emerald-200">Passo 2 de 2</p>
                                        <h2 class="mt-3 text-3xl font-black text-white">Agora é só mandar mensagem</h2>
                                        <p class="mt-3 max-w-xl text-sm leading-7 text-slate-300 sm:text-base">
                                            Seu número já está vinculado. O próximo passo é abrir a conversa oficial e mandar a primeira mensagem para começar a registrar tudo por chat.
                                        </p>

                                        <div class="mt-6 flex flex-wrap items-center gap-3">
                                            @if($tutorialWhatsappUrl)
                                                <a
                                                    href="{{ $tutorialWhatsappUrl }}"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="inline-flex items-center justify-center rounded-xl bg-emerald-400 px-5 py-3 text-sm font-bold text-slate-950 transition hover:bg-emerald-300"
                                                >
                                                    Abrir conversa no WhatsApp
                                                </a>
                                                <span class="text-sm text-slate-300">
                                                    Número: <span class="font-semibold text-white">{{ $tutorialContactNumber }}</span>
                                                </span>
                                            @else
                                                <span class="inline-flex items-center rounded-xl border border-amber-300/20 bg-amber-400/10 px-4 py-3 text-sm font-medium text-amber-100">
                                                    Configure `WHATSAPP_CONTACT_NUMBER` para mostrar o número oficial aqui.
                                                </span>
                                            @endif
                                        </div>

                                        <div class="mt-8 flex flex-wrap gap-3">
                                            <flux:button type="button" variant="primary" wire:click="dismissOnboardingTutorial">
                                                Entrar no dashboard
                                            </flux:button>
                                            <flux:button type="button" variant="ghost" wire:click="$set('onboardingStep', 1)">
                                                Voltar
                                            </flux:button>
                                        </div>
                                    </div>

                                    <div class="rounded-[1.8rem] border border-white/10 bg-[#08101d] p-5 shadow-2xl">
                                        <div class="flex items-center gap-3 border-b border-white/10 pb-4">
                                            <div class="flex h-11 w-11 items-center justify-center rounded-full bg-emerald-400/15 text-xl">💬</div>
                                            <div>
                                                <p class="font-semibold text-white">{{ $tutorialContactLabel }}</p>
                                                <p class="text-xs text-emerald-200">online agora</p>
                                            </div>
                                        </div>

                                        <div class="space-y-3 bg-[linear-gradient(180deg,rgba(255,255,255,0.02),rgba(255,255,255,0.00))] px-1 py-4">
                                            <div class="ml-auto max-w-[82%] rounded-2xl rounded-br-md bg-emerald-500 px-4 py-3 text-sm font-medium text-slate-950 shadow-lg">
                                                Gastei 32 no Uber
                                            </div>
                                            <div class="max-w-[88%] rounded-2xl rounded-bl-md bg-white/8 px-4 py-3 text-sm leading-6 text-slate-100">
                                                ✅ Registrei R$ 32,00 em Transporte (Uber).
                                            </div>
                                            <div class="ml-auto max-w-[82%] rounded-2xl rounded-br-md bg-emerald-500 px-4 py-3 text-sm font-medium text-slate-950 shadow-lg">
                                                Qual é o meu saldo?
                                            </div>
                                            <div class="max-w-[88%] rounded-2xl rounded-bl-md bg-white/8 px-4 py-3 text-sm leading-6 text-slate-100">
                                                💰 Seu saldo disponível hoje é R$ 2.540,00.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="hidden lg:flex lg:items-center">
                        <div class="w-full rounded-[2rem] border border-white/10 bg-slate-950/60 p-8 shadow-[0_32px_120px_rgba(2,6,23,0.35)]">
                            <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">O que você ganha</p>
                            <div class="mt-6 space-y-5">
                                <div class="rounded-3xl border border-white/10 bg-white/5 p-5">
                                    <p class="text-base font-semibold text-white">Seu número já reconhecido</p>
                                    <p class="mt-2 text-sm leading-7 text-slate-300">Você não precisa procurar menu nem abrir formulário toda vez. É mandar a mensagem e seguir.</p>
                                </div>
                                <div class="rounded-3xl border border-white/10 bg-white/5 p-5">
                                    <p class="text-base font-semibold text-white">Primeiro uso sem trava</p>
                                    <p class="mt-2 text-sm leading-7 text-slate-300">Seu primeiro teste já alimenta categorias, histórico e consultas úteis dentro do sistema.</p>
                                </div>
                                <div class="rounded-3xl border border-white/10 bg-white/5 p-5">
                                    <p class="text-base font-semibold text-white">Dashboard com contexto real</p>
                                    <p class="mt-2 text-sm leading-7 text-slate-300">Quando você entrar no painel, ele já vai ter informação real em vez de uma tela vazia.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
	    @else
	    <div class="relative px-2 py-4 sm:p-6 space-y-6">
	        @php
	            $onboardingChecklist = app(\App\Services\Onboarding\OnboardingChecklistService::class)->checklist(auth()->user());
	        @endphp
	        @if(($onboardingChecklist['completed'] ?? 0) < ($onboardingChecklist['total'] ?? 3))
	            <section class="rounded-3xl border border-emerald-400/20 bg-emerald-400/10 p-5 text-emerald-950 shadow-sm dark:border-emerald-400/15 dark:bg-emerald-400/5 dark:text-emerald-50">
	                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
	                    <div>
	                        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-emerald-700 dark:text-emerald-200">
	                            Checklist inicial ({{ $onboardingChecklist['completed'] ?? 0 }}/{{ $onboardingChecklist['total'] ?? 3 }})
	                        </p>
	                        <p class="mt-2 text-base font-semibold text-emerald-950 dark:text-emerald-50">
	                            Complete esses 3 passos para o painel ficar bem mais util.
	                        </p>
	                    </div>
	                    @if(! empty($tutorialWhatsappUrl))
	                        <a
	                            href="{{ $tutorialWhatsappUrl }}"
	                            target="_blank"
	                            rel="noreferrer"
	                            class="inline-flex items-center justify-center rounded-2xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-500"
	                        >
	                            Testar no WhatsApp
	                        </a>
	                    @endif
	                </div>

	                <div class="mt-4 grid gap-3 sm:grid-cols-3">
	                    @foreach(($onboardingChecklist['steps'] ?? []) as $step)
	                        <div class="rounded-2xl border border-emerald-500/10 bg-white/60 p-4 text-sm dark:border-white/10 dark:bg-slate-950/30">
	                            <p class="font-semibold text-emerald-950 dark:text-emerald-50">
	                                {{ ($step['done'] ?? false) ? 'OK' : 'Pendente' }}: {{ $step['title'] ?? '' }}
	                            </p>
	                            <p class="mt-2 text-xs leading-6 text-emerald-800/90 dark:text-emerald-100/80">
	                                {{ $step['hint'] ?? '' }}
	                            </p>
	                            <div class="mt-3 flex flex-wrap gap-2">
	                                @if(! empty($step['url']))
	                                    <a href="{{ $step['url'] }}" class="text-xs font-semibold text-emerald-700 underline underline-offset-4 hover:text-emerald-800 dark:text-emerald-200 dark:hover:text-emerald-100">
	                                        Abrir
	                                    </a>
	                                @endif
	                                @if(! empty($step['example']))
	                                    <span class="text-xs font-mono text-emerald-900/80 dark:text-emerald-100/80">{{ $step['example'] }}</span>
	                                @endif
	                            </div>
	                        </div>
	                    @endforeach
	                </div>
	            </section>
	        @endif
	        <!-- Header com Filtros -->
	        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mt-2 mb-2">
            <div>
                @php
                    $hour = now()->hour;
                    if ($hour < 12) $greeting = 'Bom dia';
                    elseif ($hour < 18) $greeting = 'Boa tarde';
                    else $greeting = 'Boa noite';
                @endphp
                <h1 class="text-2xl font-black tracking-tight text-zinc-900 dark:text-white">
                    {{ $greeting }}, {{ explode(' ', auth()->user()->name)[0] }}!
                </h1>
                <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400 mt-1">
                    {{ now()->locale('pt_BR')->translatedFormat('d \d\e F \d\e Y') }}
                </p>
            </div>
            
            <div class="flex flex-wrap items-center gap-4">
                <div class="flex items-center gap-2">
                    <flux:button 
                        variant="{{ $period === 'monthly' ? 'primary' : 'ghost' }}" 
                        wire:click="$set('period', 'monthly')"
                        size="sm"
                    >
                        Mensal
                    </flux:button>
                    <flux:button 
                        variant="{{ $period === 'yearly' ? 'primary' : 'ghost' }}" 
                        wire:click="$set('period', 'yearly')"
                        size="sm"
                    >
                        Anual
                    </flux:button>
                    <button
                        type="button"
                        x-on:click="notificationsOpen = true"
                        class="relative inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-sky-900/50 bg-slate-950 text-white shadow-[0_0_0_1px_rgba(14,165,233,0.08)] transition hover:border-sky-500/50 hover:bg-slate-900"
                        aria-label="Abrir notificacoes"
                    >
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5" />
                            <path d="M10 21a2 2 0 0 0 4 0" />
                        </svg>
                        @if($exceededBudgets->count() > 0)
                            <span class="absolute -right-1 -top-1 inline-flex min-h-5 min-w-5 items-center justify-center rounded-full bg-sky-500 px-1 text-[10px] font-semibold text-slate-950">
                                {{ $exceededBudgets->count() }}
                            </span>
                        @endif
                    </button>
                </div>
                @if($period === 'monthly')
                    <flux:input 
                        type="month" 
                        wire:model.live="selectedMonth"
                        class="w-40"
                    />
                @endif
            </div>
        </div>

        <div
            x-show="notificationsOpen"
            x-transition.opacity
            x-on:keydown.escape.window="notificationsOpen = false"
            class="fixed inset-0 z-40 bg-slate-950/60 backdrop-blur-sm"
            style="display: none;"
        ></div>

        <aside
            x-show="notificationsOpen"
            x-transition:enter="transform transition ease-out duration-300"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transform transition ease-in duration-200"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            class="fixed right-0 top-0 z-50 flex h-screen w-full max-w-md flex-col border-l border-sky-900/40 bg-slate-950 text-slate-100 shadow-2xl"
            style="display: none;"
        >
            <div class="flex items-start justify-between gap-4 border-b border-white/10 px-6 py-6">
                <div>
                    <p class="text-2xl font-black tracking-tight">Notificacoes</p>
                    <p class="mt-2 text-sm text-slate-400">Avisos importantes sem ocupar o dashboard principal.</p>
                </div>
                <button
                    type="button"
                    x-on:click="notificationsOpen = false"
                    class="inline-flex h-11 w-11 items-center justify-center rounded-full border border-sky-900/50 bg-slate-900 text-slate-200 transition hover:border-sky-500/50 hover:text-white"
                    aria-label="Fechar notificacoes"
                >
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
                        <path d="M6 6l12 12M18 6L6 18" />
                    </svg>
                </button>
            </div>

            <div class="px-6 py-5">
                <div class="rounded-2xl border border-sky-900/40 bg-slate-900/80 px-4 py-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-semibold text-slate-200">Alertas ativos</p>
                            <p class="text-xs text-slate-500">Orçamentos e avisos operacionais</p>
                        </div>
                        <span class="inline-flex h-9 min-w-9 items-center justify-center rounded-full bg-sky-500/15 px-3 text-sm font-bold text-sky-300">
                            {{ $exceededBudgets->count() }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto px-6 pb-6">
                @if($exceededBudgets->isEmpty())
                    <div class="rounded-2xl border border-emerald-500/20 bg-emerald-500/10 px-5 py-5 text-emerald-200">
                        <p class="text-base font-semibold">Nenhum alerta operacional no momento.</p>
                    </div>
                @else
                    <div class="space-y-4">
                        @foreach($exceededBudgets as $budget)
                            <article class="rounded-3xl border border-white/8 bg-slate-900/90 p-5 shadow-[0_20px_60px_rgba(2,6,23,0.45)]">
                                <div class="flex items-start gap-3">
                                    <div class="mt-1 inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-sky-500/15 text-sky-300">
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M12 3a7 7 0 0 0-7 7v3.2a2 2 0 0 1-.6 1.4L3 16h18l-1.4-1.4a2 2 0 0 1-.6-1.4V10a7 7 0 0 0-7-7Z" />
                                            <path d="M9.5 20a2.5 2.5 0 0 0 5 0" />
                                        </svg>
                                    </div>
                                    <div class="flex-1">
                                        <div class="flex items-center justify-between gap-3">
                                            <div>
                                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-300">Orçamento excedido</p>
                                                <h3 class="mt-1 text-lg font-semibold text-white">{{ $budget->category->name }}</h3>
                                            </div>
                                            <span class="rounded-full border border-red-500/20 bg-red-500/10 px-3 py-1 text-xs font-semibold text-red-200">
                                                R$ {{ number_format($budget->spent - $budget->amount, 2, ',', '.') }}
                                            </span>
                                        </div>
                                        <p class="mt-3 text-sm leading-6 text-slate-300">Seu limite foi ultrapassado nesta categoria. Vale revisar os lançamentos recentes antes de seguir com novos gastos.</p>
                                        <div class="mt-4 flex items-center justify-between gap-3 text-xs text-slate-500">
                                            <span>Orçado: R$ {{ number_format($budget->amount, 2, ',', '.') }}</span>
                                            <span>Gasto: R$ {{ number_format($budget->spent, 2, ',', '.') }}</span>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        @endforeach

                        <flux:button href="{{ route('budgets.index') }}" wire:navigate variant="primary" class="w-full justify-center">
                            Abrir Orçamentos
                        </flux:button>
                    </div>
                @endif
            </div>
        </aside>

        @php
            $mascotTone = $mascotSummary['mood']['tone'] ?? 'sky';
            $mascotPanelClass = match ($mascotTone) {
                'emerald' => 'from-emerald-400/15 via-emerald-300/10 to-transparent border-emerald-300/15',
                'amber' => 'from-amber-400/15 via-orange-300/10 to-transparent border-amber-300/15',
                'rose' => 'from-rose-400/15 via-pink-300/10 to-transparent border-rose-300/15',
                'violet' => 'from-violet-400/15 via-fuchsia-300/10 to-transparent border-violet-300/15',
                default => 'from-sky-400/15 via-cyan-300/10 to-transparent border-sky-300/15',
            };
            $mascotBadgeClass = match ($mascotTone) {
                'emerald' => 'border-emerald-300/20 bg-emerald-300/10 text-emerald-200',
                'amber' => 'border-amber-300/20 bg-amber-300/10 text-amber-100',
                'rose' => 'border-rose-300/20 bg-rose-300/10 text-rose-100',
                'violet' => 'border-violet-300/20 bg-violet-300/10 text-violet-100',
                default => 'border-sky-300/20 bg-sky-300/10 text-sky-100',
            };
        @endphp

        <div class="rounded-[2rem] border bg-gradient-to-br {{ $mascotPanelClass }} p-4 shadow-[0_24px_80px_rgba(2,6,23,0.34)] sm:p-5">
            <div class="grid gap-4 lg:grid-cols-[1fr_auto] lg:items-center">
                <div class="flex items-center gap-3">
                    <div class="inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-[1.25rem] border border-white/10 bg-white/10 text-3xl animate-[bounce_3s_ease-in-out_infinite]">
                        {{ html_entity_decode(config('mascot.emoji', '&#128640;'), ENT_QUOTES, 'UTF-8') }}
                    </div>
                    <div class="min-w-0 space-y-2">
                        <div class="inline-flex rounded-full border px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.2em] {{ $mascotBadgeClass }}">
                            {{ $mascotSummary['mood']['label'] }}
                        </div>
                        <div>
                            <h2 class="text-lg font-black text-white sm:text-xl">{{ config('mascot.name', 'Orbita') }}</h2>
                            <p class="mt-1 text-sm leading-6 text-slate-300">{{ $mascotSummary['mood']['headline'] }}</p>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-4 rounded-3xl border border-white/10 bg-slate-950/60 p-4">
                    <div class="min-w-0">
                        <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Sistema completo</p>
                        <p class="mt-1 text-sm font-semibold text-white">{{ $mascotSummary['focus_area']['title'] }}</p>
                    </div>
                    <flux:button href="{{ route(config('mascot.route_name', 'mascot.index')) }}" wire:navigate variant="primary" size="sm">
                        Ver mais
                    </flux:button>
                </div>
            </div>
        </div>

        <!-- Cards de Resumo -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 relative">
            <div wire:loading.class="absolute inset-0 z-10 bg-white/50 dark:bg-slate-950/50 backdrop-blur-sm rounded-2xl flex items-center justify-center" style="display: none;"></div>
            
            <!-- Total de Ganhos -->
            <div class="relative overflow-hidden rounded-2xl border border-zinc-200 dark:border-white/5 bg-white dark:bg-[#0a1628] p-5 shadow-sm transition-all hover:shadow-md">
                <div class="absolute -right-6 -top-6 h-24 w-24 rounded-full bg-emerald-500/10 blur-2xl"></div>
                <div class="flex items-center gap-3 mb-4">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-100 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" /></svg>
                    </div>
                    <p class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">Receitas</p>
                </div>
                <p class="text-2xl font-black text-zinc-900 dark:text-white tracking-tight">
                    R$ {{ number_format($this->getTotalIncome(), 2, ',', '.') }}
                </p>
                @if($period === 'monthly' && $this->getPreviousMonthIncome() > 0)
                    @php $variation = $this->getIncomeVariation(); @endphp
                    <p class="text-xs mt-2 font-medium {{ $variation >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }} flex items-center gap-1">
                        <span>{{ $variation >= 0 ? '↑' : '↓' }}</span> {{ number_format(abs($variation), 1) }}% vs último mês
                    </p>
                @endif
            </div>

            <!-- Total de Despesas -->
            <div class="relative overflow-hidden rounded-2xl border border-zinc-200 dark:border-white/5 bg-white dark:bg-[#0a1628] p-5 shadow-sm transition-all hover:shadow-md">
                <div class="absolute -right-6 -top-6 h-24 w-24 rounded-full bg-red-500/10 blur-2xl"></div>
                <div class="flex items-center gap-3 mb-4">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-red-100 dark:bg-red-500/10 text-red-600 dark:text-red-400">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6" /></svg>
                    </div>
                    <p class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">Despesas</p>
                </div>
                <p class="text-2xl font-black text-zinc-900 dark:text-white tracking-tight">
                    R$ {{ number_format($this->getTotalExpenses(), 2, ',', '.') }}
                </p>
                @if($period === 'monthly' && $this->getPreviousMonthExpenses() > 0)
                    @php $variation = $this->getExpensesVariation(); @endphp
                    <p class="text-xs mt-2 font-medium {{ $variation <= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }} flex items-center gap-1">
                        <span>{{ $variation >= 0 ? '↑' : '↓' }}</span> {{ number_format(abs($variation), 1) }}% vs último mês
                    </p>
                @endif
            </div>

            <!-- Total em Economias -->
            <div class="relative overflow-hidden rounded-2xl border border-zinc-200 dark:border-white/5 bg-white dark:bg-[#0a1628] p-5 shadow-sm transition-all hover:shadow-md">
                <div class="absolute -right-6 -top-6 h-24 w-24 rounded-full bg-indigo-500/10 blur-2xl"></div>
                <div class="flex items-center gap-3 mb-4">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-100 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    </div>
                    <p class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">Economias</p>
                </div>
                <p class="text-2xl font-black text-zinc-900 dark:text-white tracking-tight">
                    R$ {{ number_format($this->getTotalSavingsDeposits(), 2, ',', '.') }}
                </p>
            </div>

            <!-- Saldo disponível -->
            <div class="relative overflow-hidden rounded-2xl border border-sky-500/20 bg-gradient-to-br from-sky-500/5 to-transparent dark:from-sky-500/10 dark:to-transparent p-5 shadow-sm transition-all hover:shadow-md">
                <div class="absolute -right-6 -top-6 h-32 w-32 rounded-full bg-sky-500/20 blur-3xl"></div>
                <div class="flex items-center gap-3 mb-4">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-sky-100 dark:bg-sky-500/20 text-sky-600 dark:text-sky-400">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3" /></svg>
                    </div>
                    <p class="text-sm font-semibold text-sky-700 dark:text-sky-300">Saldo em Contas</p>
                </div>
                @php
                    $bankTotals = $this->getBankAccountsTotals();
                    $cardTotals = $this->getCreditCardsTotals();
                    $cashBalance = (float) ($bankTotals['total_balance'] ?? 0);
                    $cardDebt = (float) ($cardTotals['total_used'] ?? 0);
                    $netWorth = $cashBalance - $cardDebt;
                @endphp
                <p class="text-3xl font-black text-zinc-900 dark:text-white tracking-tight">
                    R$ {{ number_format($cashBalance, 2, ',', '.') }}
                </p>
                <p class="text-xs mt-2 font-medium text-sky-700/80 dark:text-sky-300/80">
                    Cartoes (em aberto): R$ {{ number_format($cardDebt, 2, ',', '.') }}
                    @if(($cardTotals['total_limit'] ?? 0) > 0)
                        <span class="mx-1">•</span>
                        Limite total: R$ {{ number_format((float) ($cardTotals['total_limit'] ?? 0), 2, ',', '.') }}
                    @endif
                </p>
                <p class="text-[11px] mt-1 text-zinc-500 dark:text-zinc-400">
                    Patrimonio liquido (contas - cartoes): R$ {{ number_format($netWorth, 2, ',', '.') }}
                </p>
            </div>
        </div>

        <!-- Contas e Cartoes -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
            <div class="bg-white dark:bg-[#07111f] rounded-2xl border border-zinc-200 dark:border-white/10 p-6 shadow-[0_8px_30px_rgba(0,0,0,0.12)]">
                <div class="flex items-center justify-between mb-5">
                    <div>
                        <h2 class="text-lg font-bold">Contas</h2>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">Saldo por conta (ativos)</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <flux:button href="{{ route('bank-accounts.index') }}" wire:navigate variant="ghost" size="sm">
                            Ver
                        </flux:button>
                        <flux:button href="{{ route('bank-accounts.create') }}" wire:navigate variant="ghost" size="sm">
                            Nova
                        </flux:button>
                    </div>
                </div>

                @php $accounts = $this->getBankAccountsSummary(); @endphp
                @if(count($accounts) > 0)
                    <div class="space-y-2">
                        @foreach($accounts as $account)
                            <div class="flex items-center justify-between rounded-2xl border border-zinc-200/70 dark:border-white/10 bg-zinc-50/60 dark:bg-white/[0.02] px-4 py-3">
                                <div class="flex items-center gap-3 min-w-0">
                                    <span class="h-2.5 w-2.5 rounded-full shrink-0" style="background: {{ $account['color'] ?: '#94A3B8' }}"></span>
                                    <div class="min-w-0">
                                        <p class="font-bold text-zinc-900 dark:text-zinc-100 truncate">{{ $account['name'] }}</p>
                                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ $account['type'] === 'cash' ? 'Caixa' : 'Conta' }}
                                        </p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="font-black tracking-tight {{ ($account['current_balance'] ?? 0) < 0 ? 'text-red-600 dark:text-red-400' : 'text-zinc-900 dark:text-white' }}">
                                        R$ {{ number_format($account['current_balance'] ?? 0, 2, ',', '.') }}
                                    </p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="flex flex-col items-center justify-center py-10 text-zinc-500">
                        <div class="flex h-16 w-16 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800 mb-3">
                            <svg class="h-8 w-8 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 7h18M7 7v10a2 2 0 002 2h6a2 2 0 002-2V7" />
                            </svg>
                        </div>
                        <p class="font-medium">Nenhuma conta cadastrada ainda</p>
                        <flux:button href="{{ route('bank-accounts.create') }}" wire:navigate variant="ghost" size="sm" class="mt-4">
                            Cadastrar conta
                        </flux:button>
                    </div>
                @endif
            </div>

            <div class="bg-white dark:bg-[#07111f] rounded-2xl border border-zinc-200 dark:border-white/10 p-6 shadow-[0_8px_30px_rgba(0,0,0,0.12)]">
                <div class="flex items-center justify-between mb-5">
                    <div>
                        <h2 class="text-lg font-bold">Cartoes</h2>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">Limite, uso e disponivel (ativos)</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <flux:button href="{{ route('credit-cards.index') }}" wire:navigate variant="ghost" size="sm">
                            Ver
                        </flux:button>
                        <flux:button href="{{ route('credit-cards.create') }}" wire:navigate variant="ghost" size="sm">
                            Novo
                        </flux:button>
                    </div>
                </div>

                @php $cards = $this->getCreditCardsSummary(); @endphp
                @if(count($cards) > 0)
                    <div class="space-y-2">
                        @foreach($cards as $card)
                            @php $isTight = ($card['usage_pct'] ?? 0) >= 80; @endphp
                            <div class="rounded-2xl border border-zinc-200/70 dark:border-white/10 bg-zinc-50/60 dark:bg-white/[0.02] px-4 py-3">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="min-w-0">
                                        <p class="font-bold text-zinc-900 dark:text-zinc-100 truncate">{{ $card['name'] }}</p>
                                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                                            Limite R$ {{ number_format($card['credit_limit'] ?? 0, 2, ',', '.') }}
                                            <span class="mx-1">•</span>
                                            Disponivel R$ {{ number_format($card['available_limit'] ?? 0, 2, ',', '.') }}
                                        </p>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg {{ $isTight ? 'bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-400 border border-red-500/10' : 'bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/10' }} text-xs font-bold tracking-tight">
                                            {{ number_format($card['usage_pct'] ?? 0, 1) }}%
                                        </span>
                                        @if(!empty($card['due_day']))
                                            <p class="text-[11px] text-zinc-500 dark:text-zinc-400 mt-1">Vence dia {{ $card['due_day'] }}</p>
                                        @endif
                                    </div>
                                </div>

                                <div class="mt-3 h-2 w-full rounded-full bg-zinc-200/70 dark:bg-white/10 overflow-hidden">
                                    <div class="h-full rounded-full {{ $isTight ? 'bg-red-500' : 'bg-emerald-500' }}" style="width: {{ min(100, max(0, (float) ($card['usage_pct'] ?? 0))) }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="flex flex-col items-center justify-center py-10 text-zinc-500">
                        <div class="flex h-16 w-16 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800 mb-3">
                            <svg class="h-8 w-8 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2 10h20M4 6h16a2 2 0 012 2v10a2 2 0 01-2 2H4a2 2 0 01-2-2V8a2 2 0 012-2z" />
                            </svg>
                        </div>
                        <p class="font-medium">Nenhum cartao cadastrado ainda</p>
                        <flux:button href="{{ route('credit-cards.create') }}" wire:navigate variant="ghost" size="sm" class="mt-4">
                            Cadastrar cartao
                        </flux:button>
                    </div>
                @endif
            </div>
        </div>

        <!-- Proximos vencimentos e insights -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
            <div class="bg-white dark:bg-[#07111f] rounded-2xl border border-zinc-200 dark:border-white/10 p-6 shadow-[0_8px_30px_rgba(0,0,0,0.12)]">
                <div class="flex items-center justify-between mb-5">
                    <div>
                        <h2 class="text-lg font-bold">Proximos vencimentos</h2>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">Assinaturas e faturas (proximos 30 dias)</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <flux:button href="{{ route('subscriptions.index') }}" wire:navigate variant="ghost" size="sm">
                            Assinaturas
                        </flux:button>
                        <flux:button href="{{ route('credit-cards.index') }}" wire:navigate variant="ghost" size="sm">
                            Cartoes
                        </flux:button>
                    </div>
                </div>

                @php $events = $this->getUpcomingFinancialEvents(30); @endphp
                @if(count($events) > 0)
                    <div class="space-y-2">
                        @foreach($events as $event)
                            @php
                                $daysTo = now()->startOfDay()->diffInDays($event['date'], false);
                                $isOverdue = (bool) ($event['is_overdue'] ?? false);
                                $isSoon = ! $isOverdue && $daysTo <= 3;
                                $isCard = ($event['kind'] ?? '') === 'credit_card';
                                $href = $isCard
                                    ? (isset($event['id']) ? route('credit-cards.edit', $event['id']) : route('credit-cards.index'))
                                    : (isset($event['id']) ? route('subscriptions.edit', $event['id']) : route('subscriptions.index'));
                            @endphp
                            <a href="{{ $href }}" wire:navigate class="block rounded-2xl border border-zinc-200/70 dark:border-white/10 bg-zinc-50/60 dark:bg-white/[0.02] px-4 py-3 hover:bg-zinc-100/60 dark:hover:bg-white/[0.04] transition">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="min-w-0">
                                        <p class="font-bold text-zinc-900 dark:text-zinc-100 truncate">
                                            {{ $isCard ? 'Fatura: ' : '' }}{{ $event['label'] ?? 'Item' }}
                                        </p>
                                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                                            {{ $isCard ? 'Cartao' : 'Assinatura' }}
                                            <span class="mx-1">•</span>
                                            R$ {{ number_format((float) ($event['amount'] ?? 0), 2, ',', '.') }}
                                        </p>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <p class="text-xs font-semibold text-zinc-600 dark:text-zinc-300">{{ $event['date']->format('d/m') }}</p>
                                        @if($isOverdue)
                                            <span class="mt-1 inline-flex items-center px-2 py-0.5 rounded-full bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-400 border border-red-500/10 text-[11px] font-bold">Atrasado</span>
                                        @elseif($isSoon)
                                            <span class="mt-1 inline-flex items-center px-2 py-0.5 rounded-full bg-amber-50 dark:bg-amber-500/10 text-amber-800 dark:text-amber-300 border border-amber-500/10 text-[11px] font-bold">Em {{ max(0, $daysTo) }}d</span>
                                        @else
                                            <span class="mt-1 inline-flex items-center px-2 py-0.5 rounded-full bg-zinc-100 dark:bg-white/5 text-zinc-700 dark:text-zinc-300 border border-zinc-500/10 text-[11px] font-bold">Em {{ max(0, $daysTo) }}d</span>
                                        @endif
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @else
                    <div class="flex flex-col items-center justify-center py-10 text-zinc-500">
                        <div class="flex h-16 w-16 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800 mb-3">
                            <svg class="h-8 w-8 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7h8m-8 4h8m-8 4h6M6 3h12a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V5a2 2 0 012-2z" />
                            </svg>
                        </div>
                        <p class="font-medium">Sem vencimentos no horizonte</p>
                        <flux:button href="{{ route('subscriptions.create') }}" wire:navigate variant="ghost" size="sm" class="mt-4">
                            Cadastrar assinatura
                        </flux:button>
                    </div>
                @endif
            </div>

            <div class="bg-white dark:bg-[#07111f] rounded-2xl border border-zinc-200 dark:border-white/10 p-6 shadow-[0_8px_30px_rgba(0,0,0,0.12)]">
                <div class="flex items-center justify-between mb-5">
                    <div>
                        <h2 class="text-lg font-bold">Insights</h2>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">Curto, direto e util</p>
                    </div>
                    <flux:button href="{{ route('reports.index') }}" wire:navigate variant="ghost" size="sm">
                        Relatorios
                    </flux:button>
                </div>

                @php $insights = $this->getDashboardInsights(); @endphp
                <div class="space-y-2">
                    @foreach($insights as $insight)
                        @php
                            $tone = $insight['tone'] ?? 'info';
                            $toneClass = match ($tone) {
                                'warning' => 'border-amber-500/20 bg-amber-50/70 dark:bg-amber-500/10 text-amber-900 dark:text-amber-200',
                                'ok' => 'border-emerald-500/20 bg-emerald-50/70 dark:bg-emerald-500/10 text-emerald-900 dark:text-emerald-200',
                                default => 'border-sky-500/20 bg-sky-50/70 dark:bg-sky-500/10 text-sky-900 dark:text-sky-200',
                            };
                        @endphp
                        <div class="rounded-2xl border {{ $toneClass }} px-4 py-3">
                            <p class="text-sm font-semibold leading-6">
                                {{ $insight['text'] ?? '' }}
                            </p>
                            @if(!empty($insight['cta']) && !empty($insight['cta_label']))
                                <a href="{{ $insight['cta'] }}" wire:navigate class="mt-2 inline-flex text-xs font-bold underline underline-offset-4">
                                    {{ $insight['cta_label'] }}
                                </a>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <!-- Gráfico e lista de categorias -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Despesas por Categoria -->
            <!-- Despesas por Categoria -->
            <div class="bg-white dark:bg-[#07111f] rounded-2xl border border-zinc-200 dark:border-white/10 p-6 shadow-[0_8px_30px_rgba(0,0,0,0.12)]">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-lg font-bold">Despesas por categoria</h2>
                    <flux:button variant="ghost" size="sm" icon="plus" />
                </div>

                @php
                    $expensesByCategory = $this->getExpensesByCategory();
                    $totalExpenses = collect($expensesByCategory)->sum('amount');
                    $pieLabels = collect($expensesByCategory)->pluck('category')->toArray();
                    $pieSeries = collect($expensesByCategory)->pluck('amount')->toArray();
                    $pieColors = collect($expensesByCategory)->pluck('color')->toArray();
                @endphp

                @if(count($expensesByCategory) > 0)
                    <div class="flex flex-col xl:flex-row items-center gap-8">
                        <!-- Gráfico de Donut ApexCharts -->
                        <div class="w-[200px] h-[200px] flex-shrink-0 mx-auto xl:mx-0 relative"
                            x-data="{
                                init() {
                                    const isDark = document.documentElement.classList.contains('dark');
                                    let options = {
                                        chart: { type: 'donut', height: '100%', width: '100%', fontFamily: 'inherit', background: 'transparent' },
                                        series: @js($pieSeries),
                                        labels: @js($pieLabels),
                                        colors: @js($pieColors),
                                        theme: { mode: isDark ? 'dark' : 'light' },
                                        dataLabels: { enabled: false },
                                        plotOptions: {
                                            pie: {
                                                donut: {
                                                    size: '75%',
                                                    labels: {
                                                        show: true,
                                                        name: { show: false },
                                                        value: {
                                                            show: true,
                                                            fontSize: '18px',
                                                            fontWeight: 800,
                                                            color: isDark ? '#f8fafc' : '#0f172a',
                                                            formatter: function(val) {
                                                                return 'R$ ' + val.toLocaleString('pt-BR', {minimumFractionDigits: 2});
                                                            }
                                                        },
                                                        total: {
                                                            show: true,
                                                            showAlways: true,
                                                            label: 'Total',
                                                            fontSize: '12px',
                                                            color: isDark ? '#94a3b8' : '#64748b',
                                                            formatter: function(w) {
                                                                let total = w.globals.seriesTotals.reduce((a, b) => a + b, 0);
                                                                return 'R$ ' + total.toLocaleString('pt-BR', {minimumFractionDigits: 2});
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        },
                                        stroke: { width: isDark ? 2 : 2, colors: [isDark ? '#07111f' : '#ffffff'] },
                                        legend: { show: false },
                                        tooltip: {
                                            theme: isDark ? 'dark' : 'light',
                                            y: { formatter: function(val) { return 'R$ ' + val.toLocaleString('pt-BR', {minimumFractionDigits: 2}) } }
                                        }
                                    };
                                    let chart = new window.ApexCharts(this.$refs.chart, options);
                                    chart.render();
                                }
                            }"
                        >
                            <div x-ref="chart" wire:ignore class="absolute inset-0 flex items-center justify-center"></div>
                        </div>

                        <!-- Lista de Categorias -->
                        <div class="flex-1 space-y-1 w-full">
                            @foreach(array_slice($expensesByCategory, 0, 5) as $expense)
                                <div class="flex items-center justify-between group p-3 rounded-2xl hover:bg-zinc-50 dark:hover:bg-white/[0.02] transition duration-200">
                                    <div class="flex items-center gap-4">
                                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-[14px] bg-white dark:bg-white/5 border border-zinc-200 dark:border-white/10 shadow-sm text-xl transition group-hover:scale-105 group-hover:rotate-3">
                                            {{ $expense['icon'] }}
                                        </div>
                                        <div>
                                            <p class="font-bold text-zinc-900 dark:text-zinc-100">{{ $expense['category'] }}</p>
                                            <div class="w-32 bg-zinc-200 dark:bg-zinc-800 rounded-full h-1.5 mt-1.5 overflow-hidden">
                                                <div 
                                                    class="h-full rounded-full transition-all duration-1000 ease-out" 
                                                    @style([
                                                        'width' => $expense['percentage'] . '%',
                                                        'background-color' => $expense['color'],
                                                    ])
                                                ></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-black text-zinc-900 dark:text-white">{{ $expense['percentage'] }}%</p>
                                        <p class="text-xs font-semibold text-zinc-500 dark:text-zinc-400 mt-0.5">
                                            R$ {{ number_format($expense['amount'], 2, ',', '.') }}
                                        </p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="flex flex-col items-center justify-center py-10 text-zinc-500">
                        <div class="flex h-16 w-16 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800 mb-3">
                            <svg class="h-8 w-8 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 12H4M8 16l-4-4 4-4" />
                            </svg>
                        </div>
                        <p class="font-medium">Nenhuma despesa registrada neste período</p>
                    </div>
                @endif
            </div>

            <!-- Transações Recentes -->
            <div class="bg-white dark:bg-[#07111f] rounded-2xl border border-zinc-200 dark:border-white/10 p-6 shadow-[0_8px_30px_rgba(0,0,0,0.12)]">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-lg font-bold">Transações Recentes</h2>
                    <flux:button href="{{ route('transactions.index') }}" wire:navigate variant="ghost" size="sm">
                        Ver todas
                    </flux:button>
                </div>
                
                <div class="space-y-2 max-h-96 overflow-y-auto pr-2 relative">
                    <div wire:loading.class="absolute inset-0 z-10 bg-white/50 dark:bg-[#07111f]/50 backdrop-blur-sm flex items-center justify-center rounded-lg" style="display: none;"></div>
                    @forelse($this->getRecentTransactions() as $transaction)
                        <div class="group flex items-center justify-between p-3 rounded-2xl hover:bg-zinc-50 dark:hover:bg-white/[0.02] transition duration-200 cursor-pointer">
                            <div class="flex items-center gap-4">
                                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-[14px] bg-white dark:bg-white/5 border border-zinc-200 dark:border-white/10 shadow-sm text-xl transition group-hover:scale-105 group-hover:-rotate-3">
                                    {{ $this->normalizeCategoryIcon($transaction->category?->icon) }}
                                </div>
                                <div>
                                    <p class="font-bold text-zinc-900 dark:text-zinc-100 group-hover:text-sky-500 transition-colors">{{ $transaction->description ?? 'Sem descrição' }}</p>
                                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mt-0.5">
                                        {{ $transaction->date->format('d M') }}
                                        @if($transaction->category)
                                            <span class="mx-1">•</span> {{ $transaction->category->name }}
                                        @endif
                                    </p>
                                </div>
                            </div>
                            <div class="text-right">
                                @if($transaction->type === 'income')
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 text-sm font-bold tracking-tight border border-emerald-500/10">
                                        +R$ {{ number_format($transaction->amount, 2, ',', '.') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-zinc-100 dark:bg-white/5 text-zinc-700 dark:text-zinc-300 text-sm font-bold tracking-tight border border-zinc-500/10">
                                        -R$ {{ number_format($transaction->amount, 2, ',', '.') }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="flex flex-col items-center justify-center py-10 text-zinc-500">
                            <div class="flex h-16 w-16 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800 mb-3">
                                <svg class="h-8 w-8 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                </svg>
                            </div>
                            <p class="font-medium">Nenhuma transação recente</p>
                            <flux:button href="{{ route('transactions.create') }}" wire:navigate variant="ghost" size="sm" class="mt-4">
                                Criar transação
                            </flux:button>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- Gráfico de evolução -->
        @if($period === 'monthly')
            <div class="bg-white dark:bg-[#07111f] rounded-2xl border border-zinc-200 dark:border-white/10 p-6 shadow-[0_8px_30px_rgba(0,0,0,0.12)]">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-lg font-bold">Evolução Diária</h2>
                    <div class="flex items-center gap-4">
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 bg-emerald-500 rounded-full shadow-[0_0_10px_rgba(16,185,129,0.5)]"></div>
                            <span class="text-sm font-medium text-zinc-600 dark:text-zinc-300">Receitas</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 bg-red-500 rounded-full shadow-[0_0_10px_rgba(239,68,68,0.5)]"></div>
                            <span class="text-sm font-medium text-zinc-600 dark:text-zinc-300">Despesas</span>
                        </div>
                    </div>
                </div>
                
                @php
                    $dailyData = $this->getDailyTransactions();
                    $dailyLabels = collect($dailyData)->pluck('day')->toArray();
                    $dailyIncome = collect($dailyData)->pluck('income')->toArray();
                    $dailyExpense = collect($dailyData)->pluck('expense')->toArray();
                @endphp
                <div class="relative w-full" style="min-height: 320px;" 
                    x-data="{
                        init() {
                            const isDark = document.documentElement.classList.contains('dark');
                            let options = {
                                chart: { type: 'area', height: 320, toolbar: { show: false }, background: 'transparent', fontFamily: 'inherit', parentHeightOffset: 0 },
                                theme: { mode: isDark ? 'dark' : 'light' },
                                colors: ['#10b981', '#ef4444'],
                                series: [
                                    { name: 'Receitas', data: @js($dailyIncome) },
                                    { name: 'Despesas', data: @js($dailyExpense) }
                                ],
                                xaxis: { 
                                    categories: @js($dailyLabels),
                                    labels: { style: { colors: isDark ? '#94a3b8' : '#64748b' } },
                                    axisBorder: { show: false },
                                    axisTicks: { show: false },
                                    tooltip: { enabled: false }
                                },
                                yaxis: {
                                    labels: { 
                                        style: { colors: isDark ? '#94a3b8' : '#64748b' },
                                        formatter: (value) => 'R$ ' + value.toLocaleString('pt-BR', {minimumFractionDigits: 2})
                                    }
                                },
                                dataLabels: { enabled: false },
                                stroke: { curve: 'smooth', width: 3 },
                                fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.45, opacityTo: 0.0, stops: [0, 90, 100] } },
                                tooltip: { theme: isDark ? 'dark' : 'light', y: { formatter: function (val) { return 'R$ ' + val.toLocaleString('pt-BR', {minimumFractionDigits: 2}) } } },
                                grid: { borderColor: isDark ? 'rgba(255, 255, 255, 0.05)' : 'rgba(0,0,0,0.05)', strokeDashArray: 4, xaxis: { lines: { show: true } }, yaxis: { lines: { show: true } } },
                                legend: { show: false }
                            };
                            let chart = new window.ApexCharts(this.$refs.chart, options);
                            chart.render();
                        }
                    }"
                >
                    <div x-ref="chart" class="-ml-4" wire:ignore></div>
                </div>
            </div>
        @endif

        <!-- Gráfico de evolução mensal -->
        @if($period === 'monthly')
            <div class="bg-white dark:bg-[#07111f] rounded-2xl border border-zinc-200 dark:border-white/10 p-6 shadow-[0_8px_30px_rgba(0,0,0,0.12)]">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-lg font-bold">Evolução Mensal (Últimos 12 meses)</h2>
                    <div class="flex items-center gap-4">
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 bg-emerald-500 rounded-full shadow-[0_0_10px_rgba(16,185,129,0.5)]"></div>
                            <span class="text-sm font-medium text-zinc-600 dark:text-zinc-300">Receitas</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 bg-red-500 rounded-full shadow-[0_0_10px_rgba(239,68,68,0.5)]"></div>
                            <span class="text-sm font-medium text-zinc-600 dark:text-zinc-300">Despesas</span>
                        </div>
                    </div>
                </div>
                
                @php
                    $monthlyData = $this->getMonthlyEvolution();
                    $monthlyLabels = collect($monthlyData)->map(fn($m) => Str::limit($m['monthName'], 3))->toArray();
                    $monthlyIncome = collect($monthlyData)->pluck('income')->toArray();
                    $monthlyExpense = collect($monthlyData)->pluck('expense')->toArray();
                @endphp
                <div class="relative w-full" style="min-height: 320px;" 
                    x-data="{
                        init() {
                            const isDark = document.documentElement.classList.contains('dark');
                            let options = {
                                chart: { type: 'bar', height: 320, toolbar: { show: false }, background: 'transparent', fontFamily: 'inherit', parentHeightOffset: 0 },
                                theme: { mode: isDark ? 'dark' : 'light' },
                                colors: ['#10b981', '#ef4444'],
                                series: [
                                    { name: 'Receitas', data: @js($monthlyIncome) },
                                    { name: 'Despesas', data: @js($monthlyExpense) }
                                ],
                                plotOptions: { bar: { horizontal: false, columnWidth: '55%', borderRadius: 4, borderRadiusApplication: 'end' } },
                                xaxis: { 
                                    categories: @js($monthlyLabels),
                                    labels: { style: { colors: isDark ? '#94a3b8' : '#64748b' } },
                                    axisBorder: { show: false },
                                    axisTicks: { show: false },
                                    tooltip: { enabled: false }
                                },
                                yaxis: {
                                    labels: { 
                                        style: { colors: isDark ? '#94a3b8' : '#64748b' },
                                        formatter: (value) => 'R$ ' + value.toLocaleString('pt-BR', {minimumFractionDigits: 2})
                                    }
                                },
                                dataLabels: { enabled: false },
                                stroke: { show: true, width: 2, colors: ['transparent'] },
                                tooltip: { theme: isDark ? 'dark' : 'light', y: { formatter: function (val) { return 'R$ ' + val.toLocaleString('pt-BR', {minimumFractionDigits: 2}) } } },
                                grid: { borderColor: isDark ? 'rgba(255, 255, 255, 0.05)' : 'rgba(0,0,0,0.05)', strokeDashArray: 4, xaxis: { lines: { show: true } }, yaxis: { lines: { show: true } } },
                                legend: { show: false }
                            };
                            let chart = new window.ApexCharts(this.$refs.chart, options);
                            chart.render();
                        }
                    }"
                >
                    <div x-ref="chart" class="-ml-4" wire:ignore></div>
                </div>
            </div>
        @endif

        <!-- Gráfico de evolução anual -->
        @if($period === 'yearly')
            <div class="bg-white dark:bg-[#07111f] rounded-2xl border border-zinc-200 dark:border-white/10 p-6 shadow-[0_8px_30px_rgba(0,0,0,0.12)]">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-lg font-bold">Evolução Anual (Últimos 5 anos)</h2>
                    <div class="flex items-center gap-4">
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 bg-emerald-500 rounded-full shadow-[0_0_10px_rgba(16,185,129,0.5)]"></div>
                            <span class="text-sm font-medium text-zinc-600 dark:text-zinc-300">Receitas</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 bg-red-500 rounded-full shadow-[0_0_10px_rgba(239,68,68,0.5)]"></div>
                            <span class="text-sm font-medium text-zinc-600 dark:text-zinc-300">Despesas</span>
                        </div>
                    </div>
                </div>
                
                @php
                    $yearlyData = $this->getYearlyEvolution();
                    $yearlyLabels = collect($yearlyData)->pluck('year')->toArray();
                    $yearlyIncome = collect($yearlyData)->pluck('income')->toArray();
                    $yearlyExpense = collect($yearlyData)->pluck('expense')->toArray();
                @endphp
                <div class="relative w-full" style="min-height: 320px;" 
                    x-data="{
                        init() {
                            const isDark = document.documentElement.classList.contains('dark');
                            let options = {
                                chart: { type: 'bar', height: 320, toolbar: { show: false }, background: 'transparent', fontFamily: 'inherit', parentHeightOffset: 0 },
                                theme: { mode: isDark ? 'dark' : 'light' },
                                colors: ['#10b981', '#ef4444'],
                                series: [
                                    { name: 'Receitas', data: @js($yearlyIncome) },
                                    { name: 'Despesas', data: @js($yearlyExpense) }
                                ],
                                plotOptions: { bar: { horizontal: false, columnWidth: '40%', borderRadius: 4, borderRadiusApplication: 'end' } },
                                xaxis: { 
                                    categories: @js($yearlyLabels),
                                    labels: { style: { colors: isDark ? '#94a3b8' : '#64748b' } },
                                    axisBorder: { show: false },
                                    axisTicks: { show: false },
                                    tooltip: { enabled: false }
                                },
                                yaxis: {
                                    labels: { 
                                        style: { colors: isDark ? '#94a3b8' : '#64748b' },
                                        formatter: (value) => 'R$ ' + value.toLocaleString('pt-BR', {minimumFractionDigits: 2})
                                    }
                                },
                                dataLabels: { enabled: false },
                                stroke: { show: true, width: 2, colors: ['transparent'] },
                                tooltip: { theme: isDark ? 'dark' : 'light', y: { formatter: function (val) { return 'R$ ' + val.toLocaleString('pt-BR', {minimumFractionDigits: 2}) } } },
                                grid: { borderColor: isDark ? 'rgba(255, 255, 255, 0.05)' : 'rgba(0,0,0,0.05)', strokeDashArray: 4, xaxis: { lines: { show: true } }, yaxis: { lines: { show: true } } },
                                legend: { show: false }
                            };
                            let chart = new window.ApexCharts(this.$refs.chart, options);
                            chart.render();
                        }
                    }"
                >
                    <div x-ref="chart" class="-ml-4" wire:ignore></div>
                </div>
            </div>
        @endif
    </div>
    @endif
</div>



