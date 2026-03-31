<?php

use App\Services\FinancialScoreService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

new class extends Component
{
    public string $period = 'monthly'; // 'monthly' ou 'yearly'

    public ?string $selectedMonth = null;

    public function getExceededBudgets(): \Illuminate\Database\Eloquent\Collection
    {
        return Auth::user()->budgets()
            ->with('category')
            ->get()
            ->filter(function ($budget) {
                return $budget->spent > $budget->amount;
            });
    }

    public function with(): array
    {
        return [
            'title' => 'Planejamento de '.auth()->user()->name,
            'exceededBudgets' => $this->getExceededBudgets(),
            'finnySummary' => app(FinancialScoreService::class)->sync(Auth::user()),
        ];
    }

    public function mount(): void
    {
        $this->selectedMonth = now()->format('Y-m');
    }

    public function updatedPeriod(): void
    {
        $this->selectedMonth = now()->format('Y-m');
    }

    public function getTotalIncome(): float
    {
        $user = Auth::user();
        $query = $user->transactions()->where('type', 'income');

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
        $user = Auth::user();
        $query = $user->transactions()->where('type', 'expense');

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
        $user = Auth::user();
        return (float) $user->savingsGoals()
            ->with('deposits')
            ->get()
            ->sum(fn($goal) => $goal->deposits->sum('amount'));
    }

    public function getTotalIncomeAllTime(): float
    {
        return (float) Auth::user()->transactions()
            ->where('type', 'income')
            ->sum('amount');
    }

    public function getTotalExpensesAllTime(): float
    {
        // Excluir transacoes de deposito em metas (ja sao contadas separadamente)
        $allExpenses = Auth::user()->transactions()
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

    public function getExpensesByCategory(): array
    {
        $user = Auth::user();
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
        $user = Auth::user();
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

        if (str_contains($icon, 'ð') || str_contains($icon, 'Ã')) {
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

        return (float) Auth::user()->transactions()
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

        return (float) Auth::user()->transactions()
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
        $user = Auth::user();
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
        $user = Auth::user();
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
        $user = Auth::user();
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

<div x-data="{ notificationsOpen: false }" x-cloak class="px-2 py-4 sm:p-6 space-y-6">
        <!-- Header com Filtros -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mt-2">
            <h1 class="text-2xl font-bold leading-tight">
                Planejamento de {{ auth()->user()->name }}
            </h1>
            
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
                            <p class="text-xs text-slate-500">Orcamentos e avisos operacionais</p>
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
                                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-300">Orcamento excedido</p>
                                                <h3 class="mt-1 text-lg font-semibold text-white">{{ $budget->category->name }}</h3>
                                            </div>
                                            <span class="rounded-full border border-red-500/20 bg-red-500/10 px-3 py-1 text-xs font-semibold text-red-200">
                                                R$ {{ number_format($budget->spent - $budget->amount, 2, ',', '.') }}
                                            </span>
                                        </div>
                                        <p class="mt-3 text-sm leading-6 text-slate-300">Seu limite foi ultrapassado nesta categoria. Vale revisar os lancamentos recentes antes de seguir com novos gastos.</p>
                                        <div class="mt-4 flex items-center justify-between gap-3 text-xs text-slate-500">
                                            <span>Orcado: R$ {{ number_format($budget->amount, 2, ',', '.') }}</span>
                                            <span>Gasto: R$ {{ number_format($budget->spent, 2, ',', '.') }}</span>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        @endforeach

                        <flux:button href="{{ route('budgets.index') }}" wire:navigate variant="primary" class="w-full justify-center">
                            Abrir Orcamentos
                        </flux:button>
                    </div>
                @endif
            </div>
        </aside>

        @php
            $finnyTone = $finnySummary['mood']['tone'] ?? 'sky';
            $finnyPanelClass = match ($finnyTone) {
                'emerald' => 'from-emerald-400/15 via-emerald-300/10 to-transparent border-emerald-300/15',
                'amber' => 'from-amber-400/15 via-orange-300/10 to-transparent border-amber-300/15',
                'rose' => 'from-rose-400/15 via-pink-300/10 to-transparent border-rose-300/15',
                'violet' => 'from-violet-400/15 via-fuchsia-300/10 to-transparent border-violet-300/15',
                default => 'from-sky-400/15 via-cyan-300/10 to-transparent border-sky-300/15',
            };
            $finnyBadgeClass = match ($finnyTone) {
                'emerald' => 'border-emerald-300/20 bg-emerald-300/10 text-emerald-200',
                'amber' => 'border-amber-300/20 bg-amber-300/10 text-amber-100',
                'rose' => 'border-rose-300/20 bg-rose-300/10 text-rose-100',
                'violet' => 'border-violet-300/20 bg-violet-300/10 text-violet-100',
                default => 'border-sky-300/20 bg-sky-300/10 text-sky-100',
            };
        @endphp

        <div class="rounded-[2rem] border bg-gradient-to-br {{ $finnyPanelClass }} p-6 shadow-[0_24px_80px_rgba(2,6,23,0.34)]">
            <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
                <div class="flex items-start gap-4">
                    <div class="inline-flex h-20 w-20 items-center justify-center rounded-[1.75rem] border border-white/10 bg-white/10 text-5xl">
                        &#128054;
                    </div>
                    <div class="space-y-3">
                        <div class="inline-flex rounded-full border px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.2em] {{ $finnyBadgeClass }}">
                            {{ $finnySummary['mood']['label'] }}
                        </div>
                        <div>
                            <h2 class="text-2xl font-black text-white">Finny</h2>
                            <p class="mt-2 max-w-2xl text-sm leading-7 text-slate-300">{{ $finnySummary['mood']['message'] }}</p>
                        </div>
                        <div class="flex flex-wrap gap-3 pt-1">
                            <span class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-200">
                                Score {{ $finnySummary['score'] }}/100
                            </span>
                            <span class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-200">
                                Nivel {{ $finnySummary['level'] }}
                            </span>
                            <span class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-200">
                                Sequencia {{ $finnySummary['current_streak'] }} dias
                            </span>
                            <span class="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-200">
                                Medalhas {{ $finnySummary['badges_unlocked'] }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-1">
                    <div class="rounded-3xl border border-white/10 bg-slate-950/60 p-5">
                        <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Proximo foco</p>
                        <h3 class="mt-3 text-lg font-bold text-white">{{ $finnySummary['focus_area']['title'] }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-300">{{ $finnySummary['focus_area']['description'] }}</p>
                    </div>

                    <div class="flex items-center justify-between gap-4 rounded-3xl border border-white/10 bg-slate-950/60 p-5">
                        <div>
                            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Pagina completa</p>
                            <p class="mt-3 text-lg font-bold text-white">Medalhas, XP e humor do Finny</p>
                        </div>
                        <flux:button href="{{ route('finny.index') }}" wire:navigate variant="primary">
                            Abrir Finny
                        </flux:button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cards de Resumo -->
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <!-- Total de Ganhos -->
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <p class="text-sm text-zinc-600 dark:text-zinc-400 mb-2">Total de ganhos</p>
                <p class="text-2xl font-bold text-green-600 dark:text-green-400">
                    R$ {{ number_format($this->getTotalIncome(), 2, ',', '.') }}
                </p>
                @if($period === 'monthly' && $this->getPreviousMonthIncome() > 0)
                    @php
                        $variation = $this->getIncomeVariation();
                    @endphp
                    <p class="text-xs mt-2 {{ $variation >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                        {{ $variation >= 0 ? '+' : '-' }} {{ number_format(abs($variation), 1) }}% vs mes anterior
                    </p>
                @endif
            </div>

            <!-- Total de Despesas -->
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <p class="text-sm text-zinc-600 dark:text-zinc-400 mb-2">Total de despesas</p>
                <p class="text-2xl font-bold text-red-600 dark:text-red-400">
                    R$ {{ number_format($this->getTotalExpenses(), 2, ',', '.') }}
                </p>
                @if($period === 'monthly' && $this->getPreviousMonthExpenses() > 0)
                    @php
                        $variation = $this->getExpensesVariation();
                    @endphp
                    <p class="text-xs mt-2 {{ $variation <= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                        {{ $variation >= 0 ? '+' : '-' }} {{ number_format(abs($variation), 1) }}% vs mes anterior
                    </p>
                @endif
            </div>

            <!-- Total de dividas -->
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <p class="text-sm text-zinc-600 dark:text-zinc-400 mb-2">Total de dividas</p>
                <p class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                    R$ 0,00
                </p>
            </div>

            <!-- Total em Economias -->
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <p class="text-sm text-zinc-600 dark:text-zinc-400 mb-2">Total em economias</p>
                <p class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                    R$ {{ number_format($this->getTotalSavingsDeposits(), 2, ',', '.') }}
                </p>
            </div>

            <!-- Saldo disponivel -->
            <div class="bg-zinc-900 dark:bg-zinc-950 rounded-xl border border-zinc-700 p-6">
                <p class="text-sm text-zinc-400 mb-2">Saldo disponivel</p>
                <p class="text-2xl font-bold text-white">
                    R$ {{ number_format($this->getAvailableBalance(), 2, ',', '.') }}
                </p>
            </div>
        </div>

        <!-- Grafico e lista de categorias -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Despesas por Categoria -->
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-lg font-semibold">Despesas por categoria</h2>
                    <flux:button variant="ghost" size="sm" icon="plus" />
                </div>

                @php
                    $expensesByCategory = $this->getExpensesByCategory();
                    $totalExpenses = collect($expensesByCategory)->sum('amount');
                @endphp

                @if(count($expensesByCategory) > 0)
                    <div class="flex flex-col md:flex-row items-start md:items-center gap-6">
                        <!-- Grafico de pizza simples (usando SVG) -->
                        <div class="w-40 h-40 flex-shrink-0 mx-auto md:mx-0">
                            <svg viewBox="0 0 100 100" class="w-full h-full transform -rotate-90">
                                <defs>
                                    <style>
                                        .pie-segment {
                                            stroke: white;
                                            stroke-width: 2;
                                        }
                                        .dark .pie-segment {
                                            stroke: #18181b;
                                        }
                                    </style>
                                </defs>
                                @php
                                    $currentAngle = 0;
                                @endphp
                                @foreach($expensesByCategory as $index => $expense)
                                    @php
                                        $percentage = $expense['percentage'];
                                        $angle = ($percentage / 100) * 360;
                                        $x1 = 50 + 50 * cos(deg2rad($currentAngle));
                                        $y1 = 50 + 50 * sin(deg2rad($currentAngle));
                                        $x2 = 50 + 50 * cos(deg2rad($currentAngle + $angle));
                                        $y2 = 50 + 50 * sin(deg2rad($currentAngle + $angle));
                                        $largeArc = $angle > 180 ? 1 : 0;
                                    @endphp
                                    <path
                                        d="M 50 50 L {{ $x1 }} {{ $y1 }} A 50 50 0 {{ $largeArc }} 1 {{ $x2 }} {{ $y2 }} Z"
                                        fill="{{ $expense['color'] }}"
                                        class="pie-segment"
                                    />
                                    @php
                                        $currentAngle += $angle;
                                    @endphp
                                @endforeach
                            </svg>
                        </div>

                        <!-- Lista de Categorias -->
                        <div class="flex-1 space-y-3">
                            @foreach(array_slice($expensesByCategory, 0, 5) as $expense)
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <span class="text-2xl">{{ $expense['icon'] }}</span>
                                        <div>
                                            <p class="font-medium">{{ $expense['category'] }}</p>
                                            <div class="w-32 bg-zinc-200 dark:bg-zinc-700 rounded-full h-2">
                                                <div 
                                                    class="h-2 rounded-full" 
                                                    style="width: {{ $expense['percentage'] }}%; background-color: {{ $expense['color'] }};"
                                                ></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-semibold">{{ $expense['percentage'] }}%</p>
                                        <p class="text-sm text-zinc-600 dark:text-zinc-400">
                                            R$ {{ number_format($expense['amount'], 2, ',', '.') }}
                                        </p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="text-center py-8 text-zinc-500">
                        <p>Nenhuma despesa registrada neste periodo</p>
                    </div>
                @endif
            </div>

            <!-- Transacoes recentes -->
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-lg font-semibold">Transacoes Recentes</h2>
                    <flux:button href="{{ route('transactions.index') }}" wire:navigate variant="ghost" size="sm">
                        Ver todas
                    </flux:button>
                </div>
                
                <div class="space-y-3 max-h-96 overflow-y-auto">
                    @forelse($this->getRecentTransactions() as $transaction)
                        <div class="flex items-center justify-between p-3 rounded-lg bg-zinc-50 dark:bg-zinc-800 hover:bg-zinc-100 dark:hover:bg-zinc-700 transition-colors">
                            <div class="flex items-center gap-3">
                                <span class="text-xl">{{ $this->normalizeCategoryIcon($transaction->category?->icon) }}</span>
                                <div>
                                    <p class="font-medium">{{ $transaction->description ?? 'Sem descricao' }}</p>
                                    <p class="text-sm text-zinc-600 dark:text-zinc-400">
                                        {{ $transaction->date->format('d/m/Y') }}
                                        @if($transaction->category)
                                            - {{ $transaction->category->name }}
                                        @endif
                                    </p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="font-semibold {{ $transaction->type === 'income' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                    {{ $transaction->type === 'income' ? '+' : '-' }}R$ {{ number_format($transaction->amount, 2, ',', '.') }}
                                </p>
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-8 text-zinc-500">
                            <p>Nenhuma transacao registrada</p>
                            <flux:button href="{{ route('transactions.create') }}" wire:navigate variant="ghost" size="sm" class="mt-4">
                                Criar primeira transacao
                            </flux:button>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- Grafico de evolucao -->
        @if($period === 'monthly')
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <h2 class="text-lg font-semibold mb-6">Evolucao Diaria</h2>
                @php
                    $dailyData = $this->getDailyTransactions();
                    $maxValue = max(
                        collect($dailyData)->max('income'),
                        collect($dailyData)->max('expense')
                    ) ?: 1;
                @endphp
                <div class="bg-gradient-to-t from-zinc-100 to-zinc-50 dark:from-zinc-800 dark:to-zinc-900 rounded-lg p-4 border-2 border-zinc-200 dark:border-zinc-700">
                    <div 
                        class="h-80 flex items-end gap-1 overflow-x-auto pb-2 scroll-smooth"
                        x-init="setTimeout(() => { 
                            const today = $el.querySelector('.is-today');
                            if (today) today.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
                        }, 500)"
                    >
                        @foreach($dailyData as $index => $day)
                            <div class="flex flex-col items-center gap-2 group shrink-0 {{ $day['isToday'] ? 'is-today' : '' }}" style="width: {{ 100 / min(30, count($dailyData)) }}%; min-width: 24px;">
                                <div class="w-full flex flex-col justify-end gap-1 bg-transparent relative" style="height: 280px; min-height: 280px;">
                                    @if($day['income'] > 0)
                                        @php
                                            $incomePercent = ($day['income'] / $maxValue) * 100;
                                            $incomeHeight = max($incomePercent, 5);
                                            $incomeMinPx = max(($incomeHeight / 100) * 280, 15);
                                        @endphp
                                        <div 
                                            class="w-full bg-green-600 dark:bg-green-500 rounded-t-md hover:bg-green-700 dark:hover:bg-green-400 transition-all shadow-md hover:shadow-lg"
                                            style="height: {{ $incomeHeight }}%; min-height: {{ $incomeMinPx }}px;"
                                            title="Receita: R$ {{ number_format($day['income'], 2, ',', '.') }}"
                                        ></div>
                                    @endif
                                    @if($day['expense'] > 0)
                                        @php
                                            $expensePercent = ($day['expense'] / $maxValue) * 100;
                                            $expenseHeight = max($expensePercent, 5);
                                            $expenseMinPx = max(($expenseHeight / 100) * 280, 15);
                                        @endphp
                                        <div 
                                            class="w-full bg-red-600 dark:bg-red-500 rounded-b-md hover:bg-red-700 dark:hover:bg-red-400 transition-all shadow-md hover:shadow-lg"
                                            style="height: {{ $expenseHeight }}%; min-height: {{ $expenseMinPx }}px;"
                                            title="Despesa: R$ {{ number_format($day['expense'], 2, ',', '.') }}"
                                        ></div>
                                    @endif
                                </div>
                                <span class="text-[10px] text-zinc-700 dark:text-zinc-300 opacity-80 group-hover:opacity-100 transition-opacity font-medium text-center leading-tight">
                                    {{ $day['day'] }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="flex items-center justify-center gap-6 mt-4">
                    <div class="flex items-center gap-2">
                        <div class="w-4 h-4 bg-green-500 dark:bg-green-600 rounded border border-green-600 dark:border-green-500"></div>
                        <span class="text-sm text-zinc-600 dark:text-zinc-400">Receitas</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="w-4 h-4 bg-red-500 dark:bg-red-600 rounded border border-red-600 dark:border-red-500"></div>
                        <span class="text-sm text-zinc-600 dark:text-zinc-400">Despesas</span>
                    </div>
                </div>
            </div>
        @endif

        <!-- Grafico de evolucao -->
        @if($period === 'monthly')
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <h2 class="text-lg font-semibold mb-6">Evolucao Mensal (Ultimos 12 meses)</h2>
                @php
                    $monthlyData = $this->getMonthlyEvolution();
                    $maxMonthlyValue = max(
                        collect($monthlyData)->max('income'),
                        collect($monthlyData)->max('expense')
                    ) ?: 1;
                @endphp
                <div class="bg-gradient-to-t from-zinc-100 to-zinc-50 dark:from-zinc-800 dark:to-zinc-900 rounded-lg p-4 border-2 border-zinc-200 dark:border-zinc-700">
                    <div 
                        class="h-80 flex items-end gap-2 overflow-x-auto pb-2 scroll-smooth"
                        x-init="setTimeout(() => { 
                            const current = $el.querySelector('.is-current');
                            if (current) current.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
                        }, 600)"
                    >
                        @foreach($monthlyData as $month)
                            <div class="flex flex-col items-center gap-2 group shrink-0 {{ $month['isCurrent'] ? 'is-current' : '' }}" style="width: {{ 100 / count($monthlyData) }}%; min-width: 60px;">
                                <div class="w-full flex flex-col justify-end gap-1 bg-transparent relative" style="height: 280px; min-height: 280px;">
                                    @if($month['income'] > 0)
                                        @php
                                            $incomeHeight = max(($month['income'] / $maxMonthlyValue) * 100, 5);
                                            $incomeMinPx = max(($incomeHeight / 100) * 280, 15);
                                        @endphp
                                        <div 
                                            class="w-full bg-green-600 dark:bg-green-500 rounded-t-md hover:bg-green-700 dark:hover:bg-green-400 transition-all shadow-md"
                                            style="height: {{ $incomeHeight }}%; min-height: {{ $incomeMinPx }}px;"
                                            title="Receita: R$ {{ number_format($month['income'], 2, ',', '.') }}"
                                        ></div>
                                    @endif
                                    @if($month['expense'] > 0)
                                        @php
                                            $expenseHeight = max(($month['expense'] / $maxMonthlyValue) * 100, 5);
                                            $expenseMinPx = max(($expenseHeight / 100) * 280, 15);
                                        @endphp
                                        <div 
                                            class="w-full bg-red-600 dark:bg-red-500 rounded-b-md hover:bg-red-700 dark:hover:bg-red-400 transition-all shadow-md"
                                            style="height: {{ $expenseHeight }}%; min-height: {{ $expenseMinPx }}px;"
                                            title="Despesa: R$ {{ number_format($month['expense'], 2, ',', '.') }}"
                                        ></div>
                                    @endif
                                </div>
                                <span class="text-[10px] text-zinc-700 dark:text-zinc-300 opacity-80 group-hover:opacity-100 transition-opacity font-medium text-center leading-tight">
                                    {{ Str::limit($month['monthName'], 3) }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="flex items-center justify-center gap-6 mt-4">
                    <div class="flex items-center gap-2">
                        <div class="w-4 h-4 bg-green-500 dark:bg-green-600 rounded border border-green-600 dark:border-green-500"></div>
                        <span class="text-sm text-zinc-600 dark:text-zinc-400">Receitas</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="w-4 h-4 bg-red-500 dark:bg-red-600 rounded border border-red-600 dark:border-red-500"></div>
                        <span class="text-sm text-zinc-600 dark:text-zinc-400">Despesas</span>
                    </div>
                </div>
            </div>
        @endif

        <!-- Grafico de evolucao -->
        @if($period === 'yearly')
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <h2 class="text-lg font-semibold mb-6">Evolucao Anual (Ultimos 5 anos)</h2>
                @php
                    $yearlyData = $this->getYearlyEvolution();
                    $maxYearlyValue = max(
                        collect($yearlyData)->max('income'),
                        collect($yearlyData)->max('expense')
                    ) ?: 1;
                @endphp
                <div class="bg-gradient-to-t from-zinc-100 to-zinc-50 dark:from-zinc-800 dark:to-zinc-900 rounded-lg p-4 border-2 border-zinc-200 dark:border-zinc-700">
                    <div class="h-80 flex items-end gap-4 overflow-x-auto pb-2">
                        @foreach($yearlyData as $year)
                            <div class="flex flex-col items-center gap-2 group shrink-0" style="width: {{ 100 / count($yearlyData) }}%; min-width: 80px;">
                                <div class="w-full flex flex-col justify-end gap-1 bg-transparent relative" style="height: 280px; min-height: 280px;">
                                    @if($year['income'] > 0)
                                        @php
                                            $incomeHeight = max(($year['income'] / $maxYearlyValue) * 100, 5);
                                            $incomeMinPx = max(($incomeHeight / 100) * 280, 15);
                                        @endphp
                                        <div 
                                            class="w-full bg-green-600 dark:bg-green-500 rounded-t-md hover:bg-green-700 dark:hover:bg-green-400 transition-all shadow-md"
                                            style="height: {{ $incomeHeight }}%; min-height: {{ $incomeMinPx }}px;"
                                            title="Receita: R$ {{ number_format($year['income'], 2, ',', '.') }}"
                                        ></div>
                                    @endif
                                    @if($year['expense'] > 0)
                                        @php
                                            $expenseHeight = max(($year['expense'] / $maxYearlyValue) * 100, 5);
                                            $expenseMinPx = max(($expenseHeight / 100) * 280, 15);
                                        @endphp
                                        <div 
                                            class="w-full bg-red-600 dark:bg-red-500 rounded-b-md hover:bg-red-700 dark:hover:bg-red-400 transition-all shadow-md"
                                            style="height: {{ $expenseHeight }}%; min-height: {{ $expenseMinPx }}px;"
                                            title="Despesa: R$ {{ number_format($year['expense'], 2, ',', '.') }}"
                                        ></div>
                                    @endif
                                </div>
                                <span class="text-xs text-zinc-700 dark:text-zinc-300 opacity-80 group-hover:opacity-100 transition-opacity font-medium">
                                    {{ $year['year'] }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="flex items-center justify-center gap-6 mt-4">
                    <div class="flex items-center gap-2">
                        <div class="w-4 h-4 bg-green-500 dark:bg-green-600 rounded border border-green-600 dark:border-green-500"></div>
                        <span class="text-sm text-zinc-600 dark:text-zinc-400">Receitas</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="w-4 h-4 bg-red-500 dark:bg-red-600 rounded border border-red-600 dark:border-red-500"></div>
                        <span class="text-sm text-zinc-600 dark:text-zinc-400">Despesas</span>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
