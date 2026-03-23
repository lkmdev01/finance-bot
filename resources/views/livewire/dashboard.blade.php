<?php

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
        // Excluir transações de depósito em metas (já são contadas separadamente)
        $allExpenses = Auth::user()->transactions()
            ->where('type', 'expense')
            ->get();
        
        // Filtrar transações que não são depósitos em metas
        $expensesWithoutSavings = $allExpenses->filter(function ($transaction) {
            $metadata = $transaction->metadata ?? [];
            return !isset($metadata['savings_goal_deposit_id']);
        });
        
        return (float) $expensesWithoutSavings->sum('amount');
    }

    public function getAvailableBalance(): float
    {
        // Saldo disponível considera TODAS as transações, não apenas do período
        // Depósitos em metas são deduzidos separadamente (não contam como despesas normais)
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
                'icon' => $expense->category->icon ?? '📦',
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
        
        // Últimos 12 meses
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
        
        // Últimos 5 anos
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

<div class="px-2 py-4 sm:p-6 space-y-6">
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

        <!-- Alertas de Orçamentos Excedidos -->
        @if($exceededBudgets->count() > 0)
            <div class="bg-red-50 dark:bg-red-900/20 border-2 border-red-500 dark:border-red-600 rounded-xl p-4">
                <div class="flex items-start gap-3">
                    <div class="flex-shrink-0 w-8 h-8 rounded-full bg-red-100 dark:bg-red-900 flex items-center justify-center">
                        <span class="text-red-600 dark:text-red-400 text-lg">⚠</span>
                    </div>
                    <div class="flex-1">
                        <h3 class="font-semibold text-red-900 dark:text-red-200 mb-2">
                            Orçamentos Excedidos ({{ $exceededBudgets->count() }})
                        </h3>
                        <ul class="space-y-1">
                            @foreach($exceededBudgets as $budget)
                                <li class="text-sm text-red-800 dark:text-red-300">
                                    <strong>{{ $budget->category->name }}:</strong> 
                                    Excedido em R$ {{ number_format($budget->spent - $budget->amount, 2, ',', '.') }}
                                </li>
                            @endforeach
                        </ul>
                        <flux:button 
                            href="{{ route('budgets.index') }}" 
                            wire:navigate 
                            variant="ghost" 
                            size="sm" 
                            class="mt-3"
                        >
                            Ver Orçamentos
                        </flux:button>
                    </div>
                </div>
            </div>
        @endif

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
                        {{ $variation >= 0 ? '↑' : '↓' }} {{ number_format(abs($variation), 1) }}% vs mês anterior
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
                        {{ $variation >= 0 ? '↑' : '↓' }} {{ number_format(abs($variation), 1) }}% vs mês anterior
                    </p>
                @endif
            </div>

            <!-- Total de Dívidas -->
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <p class="text-sm text-zinc-600 dark:text-zinc-400 mb-2">Total de dívidas</p>
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

            <!-- Saldo Disponível -->
            <div class="bg-zinc-900 dark:bg-zinc-950 rounded-xl border border-zinc-700 p-6">
                <p class="text-sm text-zinc-400 mb-2">Saldo disponível</p>
                <p class="text-2xl font-bold text-white">
                    R$ {{ number_format($this->getAvailableBalance(), 2, ',', '.') }}
                </p>
            </div>
        </div>

        <!-- Gráfico e Lista de Categorias -->
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
                        <!-- Gráfico de Pizza Simples (usando SVG) -->
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
                        <p>Nenhuma despesa registrada neste período</p>
                    </div>
                @endif
            </div>

            <!-- Transações Recentes -->
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-lg font-semibold">Transações Recentes</h2>
                    <flux:button href="{{ route('transactions.index') }}" wire:navigate variant="ghost" size="sm">
                        Ver todas
                    </flux:button>
                </div>
                
                <div class="space-y-3 max-h-96 overflow-y-auto">
                    @forelse($this->getRecentTransactions() as $transaction)
                        <div class="flex items-center justify-between p-3 rounded-lg bg-zinc-50 dark:bg-zinc-800 hover:bg-zinc-100 dark:hover:bg-zinc-700 transition-colors">
                            <div class="flex items-center gap-3">
                                <span class="text-xl">{{ $transaction->category?->icon ?? '📦' }}</span>
                                <div>
                                    <p class="font-medium">{{ $transaction->description ?? 'Sem descrição' }}</p>
                                    <p class="text-sm text-zinc-600 dark:text-zinc-400">
                                        {{ $transaction->date->format('d/m/Y') }}
                                        @if($transaction->category)
                                            • {{ $transaction->category->name }}
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
                            <p>Nenhuma transação registrada</p>
                            <flux:button href="{{ route('transactions.create') }}" wire:navigate variant="ghost" size="sm" class="mt-4">
                                Criar primeira transação
                            </flux:button>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- Gráfico de Evolução -->
        @if($period === 'monthly')
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <h2 class="text-lg font-semibold mb-6">Evolução Diária</h2>
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

        <!-- Gráfico de Evolução Mensal -->
        @if($period === 'monthly')
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <h2 class="text-lg font-semibold mb-6">Evolução Mensal (Últimos 12 meses)</h2>
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

        <!-- Gráfico de Evolução Anual -->
        @if($period === 'yearly')
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <h2 class="text-lg font-semibold mb-6">Evolução Anual (Últimos 5 anos)</h2>
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
