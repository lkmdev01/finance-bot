<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public string $period = 'monthly';
    public ?string $selectedMonth = null;
    public int $year;
    public string $format = 'view';

    public function mount(): void
    {
        $this->selectedMonth = now()->format('Y-m');
        $this->year = now()->year;
    }

    public function exportPdf(): void
    {
        $this->redirect(route('reports.export.pdf', [
            'period' => $this->period,
            'selectedMonth' => $this->selectedMonth,
            'year' => $this->year,
        ]));
    }

    public function exportExcel(): void
    {
        $this->redirect(route('reports.export.excel', [
            'period' => $this->period,
            'selectedMonth' => $this->selectedMonth,
            'year' => $this->year,
        ]));
    }

    public function with(): array
    {
        $user = Auth::user();
        
        if ($this->period === 'monthly') {
            [$year, $month] = explode('-', $this->selectedMonth);
            $startDate = \Carbon\Carbon::create($year, $month, 1)->startOfMonth();
            $endDate = \Carbon\Carbon::create($year, $month, 1)->endOfMonth();
        } else {
            $startDate = \Carbon\Carbon::create($this->year, 1, 1)->startOfYear();
            $endDate = \Carbon\Carbon::create($this->year, 12, 31)->endOfYear();
        }

        $transactions = $user->transactions()
            ->whereBetween('date', [$startDate, $endDate])
            ->with('category')
            ->orderBy('date')
            ->get();

        $totalIncome = $transactions->where('type', 'income')->sum('amount');
        $totalExpenses = $transactions->where('type', 'expense')->sum('amount');
        
        // Calcular saldo disponível (mesma lógica do Dashboard)
        $totalIncomeAllTime = (float) $user->transactions()
            ->where('type', 'income')
            ->sum('amount');

        // Excluir transações de depósito em metas
        $allExpenses = $user->transactions()
            ->where('type', 'expense')
            ->get();

        $expensesWithoutSavings = $allExpenses->filter(function ($transaction) {
            $metadata = $transaction->metadata ?? [];
            return !isset($metadata['savings_goal_deposit_id']);
        });

        $totalExpensesAllTime = (float) $expensesWithoutSavings->sum('amount');

        $totalSavingsDeposits = (float) $user->savingsGoals()
            ->with('deposits')
            ->get()
            ->sum(fn ($goal) => $goal->deposits->sum('amount'));

        $availableBalance = $totalIncomeAllTime - $totalExpensesAllTime - $totalSavingsDeposits;
        
        $expensesByCategory = $transactions
            ->where('type', 'expense')
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->map(function ($group) {
                return [
                    'category' => $group->first()->category->name,
                    'icon' => $group->first()->category->icon ?? '📦',
                    'amount' => $group->sum('amount'),
                    'count' => $group->count(),
                ];
            })
            ->sortByDesc('amount')
            ->values();

        $incomeByCategory = $transactions
            ->where('type', 'income')
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->map(function ($group) {
                return [
                    'category' => $group->first()->category->name,
                    'icon' => $group->first()->category->icon ?? '📦',
                    'amount' => $group->sum('amount'),
                    'count' => $group->count(),
                ];
            })
            ->sortByDesc('amount')
            ->values();

        return [
            'transactions' => $transactions,
            'totalIncome' => $totalIncome,
            'totalExpenses' => $totalExpenses,
            'balance' => $totalIncome - $totalExpenses, // Saldo do período
            'availableBalance' => $availableBalance, // Saldo disponível (all-time)
            'expensesByCategory' => $expensesByCategory,
            'incomeByCategory' => $incomeByCategory,
            'transactionCount' => $transactions->count(),
        ];
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Relatórios</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Análise detalhada das suas finanças</p>
        </div>
        <div class="flex items-center gap-3">
            <flux:dropdown>
                <flux:button variant="ghost" icon="arrow-down-tray">
                    Exportar
                </flux:button>
                <flux:menu>
                    <flux:menu.item wire:click="exportPdf" icon="document-text">
                        Exportar como PDF
                    </flux:menu.item>
                    <flux:menu.item wire:click="exportExcel" icon="table-cells">
                        Exportar como Excel
                    </flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    <!-- Filtros -->
    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <flux:select wire:model.live="period" label="Período">
                <option value="monthly">Mensal</option>
                <option value="yearly">Anual</option>
            </flux:select>

            @if($period === 'monthly')
                <flux:input type="month" wire:model.live="selectedMonth" label="Mês" />
            @else
                <flux:input type="number" wire:model.live="year" label="Ano" min="2020" max="2100" />
            @endif
        </div>
    </div>

    <!-- Resumo Geral -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mb-2">Total de Receitas</p>
            <p class="text-2xl font-bold text-green-600 dark:text-green-400">
                R$ {{ number_format($totalIncome, 2, ',', '.') }}
            </p>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-2">
                {{ $incomeByCategory->count() }} categoria(s)
            </p>
        </div>

        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mb-2">Total de Despesas</p>
            <p class="text-2xl font-bold text-red-600 dark:text-red-400">
                R$ {{ number_format($totalExpenses, 2, ',', '.') }}
            </p>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-2">
                {{ $expensesByCategory->count() }} categoria(s)
            </p>
        </div>

        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mb-2">Saldo do Período</p>
            <p class="text-2xl font-bold {{ $balance >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                R$ {{ number_format($balance, 2, ',', '.') }}
            </p>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-2">
                {{ $transactionCount }} transação(ões)
            </p>
        </div>
        
        <!-- Saldo Disponível (All-time) -->
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mb-2">Saldo Disponível</p>
            <p class="text-2xl font-bold {{ $availableBalance >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                R$ {{ number_format($availableBalance, 2, ',', '.') }}
            </p>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-2">
                Total acumulado (todas as transações)
            </p>
        </div>
    </div>

    <!-- Despesas por Categoria -->
    @if($expensesByCategory->isNotEmpty())
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
            <h2 class="text-lg font-semibold mb-6">Despesas por Categoria</h2>
            <div class="space-y-4">
                @foreach($expensesByCategory as $item)
                    <div class="flex items-center justify-between p-4 rounded-lg bg-zinc-50 dark:bg-zinc-800">
                        <div class="flex items-center gap-3">
                            <span class="text-2xl">{{ $item['icon'] }}</span>
                            <div>
                                <p class="font-medium">{{ $item['category'] }}</p>
                                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ $item['count'] }} transação(ões)
                                </p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-lg font-bold text-red-600 dark:text-red-400">
                                R$ {{ number_format($item['amount'], 2, ',', '.') }}
                            </p>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $totalExpenses > 0 ? number_format(($item['amount'] / $totalExpenses) * 100, 1) : 0 }}% do total
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <!-- Receitas por Categoria -->
    @if($incomeByCategory->isNotEmpty())
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
            <h2 class="text-lg font-semibold mb-6">Receitas por Categoria</h2>
            <div class="space-y-4">
                @foreach($incomeByCategory as $item)
                    <div class="flex items-center justify-between p-4 rounded-lg bg-zinc-50 dark:bg-zinc-800">
                        <div class="flex items-center gap-3">
                            <span class="text-2xl">{{ $item['icon'] }}</span>
                            <div>
                                <p class="font-medium">{{ $item['category'] }}</p>
                                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ $item['count'] }} transação(ões)
                                </p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-lg font-bold text-green-600 dark:text-green-400">
                                R$ {{ number_format($item['amount'], 2, ',', '.') }}
                            </p>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $totalIncome > 0 ? number_format(($item['amount'] / $totalIncome) * 100, 1) : 0 }}% do total
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <!-- Lista Completa de Transações -->
    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
        <h2 class="text-lg font-semibold mb-6">Todas as Transações</h2>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Data</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Descrição</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Categoria</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Tipo</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Valor</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse($transactions as $transaction)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800">
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                {{ $transaction->date->format('d/m/Y') }}
                            </td>
                            <td class="px-6 py-4 text-sm">
                                {{ $transaction->description ?? '-' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                @if($transaction->category)
                                    <span class="inline-flex items-center gap-2">
                                        @if($transaction->category->icon)
                                            <span>{{ $transaction->category->icon }}</span>
                                        @endif
                                        {{ $transaction->category->name }}
                                    </span>
                                @else
                                    <span class="text-zinc-400">-</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 py-1 text-xs font-medium rounded-full {{ $transaction->type === 'income' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' }}">
                                    {{ $transaction->type === 'income' ? 'Receita' : 'Despesa' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium {{ $transaction->type === 'income' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                {{ $transaction->type === 'income' ? '+' : '-' }}R$ {{ number_format($transaction->amount, 2, ',', '.') }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">
                                Nenhuma transação encontrada neste período.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
