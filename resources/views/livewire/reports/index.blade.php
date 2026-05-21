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

        $totalIncomeAllTime = (float) $user->transactions()
            ->where('type', 'income')
            ->sum('amount');

        $allExpenses = $user->transactions()
            ->where('type', 'expense')
            ->get();

        $expensesWithoutSavings = $allExpenses->filter(function ($transaction) {
            $metadata = $transaction->metadata ?? [];

            return ! isset($metadata['savings_goal_deposit_id']);
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
                    'icon' => $group->first()->category->icon ?: '●',
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
                    'icon' => $group->first()->category->icon ?: '●',
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
            'balance' => $totalIncome - $totalExpenses,
            'availableBalance' => $availableBalance,
            'expensesByCategory' => $expensesByCategory,
            'incomeByCategory' => $incomeByCategory,
            'transactionCount' => $transactions->count(),
        ];
    }
}; ?>

<div class="space-y-6 p-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-sky-500 dark:text-sky-300">Análise</p>
            <h1 class="mt-2 text-3xl font-black tracking-tight text-zinc-900 dark:text-white">Relatórios</h1>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-zinc-500 dark:text-zinc-400">
                Consolide receitas, despesas, saldo e distribuição por categoria no período que você quiser revisar.
            </p>
        </div>

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

    <div class="rounded-[2rem] border border-sky-900/40 bg-slate-950 p-5 text-slate-100 shadow-[0_24px_80px_rgba(2,6,23,0.34)]">
        <div class="grid gap-4 lg:grid-cols-[1.1fr_0.9fr]">
            <div class="rounded-3xl border border-white/10 bg-white/5 p-5">
                <h2 class="text-base font-bold text-white">Filtros do relatório</h2>
                <p class="mt-2 text-sm text-slate-300">Escolha se quer olhar o fechamento mensal ou anual antes de exportar.</p>

                <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-3">
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

            <div class="rounded-3xl border border-white/10 bg-gradient-to-br from-sky-500/12 via-sky-500/5 to-transparent p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-sky-200">Leitura rápida</p>
                <p class="mt-3 text-sm text-slate-300">Saldo disponível acumulado hoje</p>
                <p class="mt-4 text-4xl font-black tracking-tight {{ $availableBalance >= 0 ? 'text-emerald-300' : 'text-rose-300' }}">
                    R$ {{ number_format($availableBalance, 2, ',', '.') }}
                </p>
                <p class="mt-3 text-xs text-slate-400">Esse indicador considera todo o histórico, desconsiderando depósitos feitos em metas.</p>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div class="relative overflow-hidden rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm transition-all hover:shadow-md dark:border-white/10 dark:bg-[#07111f]">
            <div class="absolute -right-6 -top-6 h-24 w-24 rounded-full bg-emerald-500/12 blur-2xl"></div>
            <p class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">Total de Receitas</p>
            <p class="mt-3 text-3xl font-black tracking-tight text-emerald-600 dark:text-emerald-400">
                R$ {{ number_format($totalIncome, 2, ',', '.') }}
            </p>
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ $incomeByCategory->count() }} categoria(s)</p>
        </div>

        <div class="relative overflow-hidden rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm transition-all hover:shadow-md dark:border-white/10 dark:bg-[#07111f]">
            <div class="absolute -right-6 -top-6 h-24 w-24 rounded-full bg-rose-500/12 blur-2xl"></div>
            <p class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">Total de Despesas</p>
            <p class="mt-3 text-3xl font-black tracking-tight text-red-600 dark:text-red-400">
                R$ {{ number_format($totalExpenses, 2, ',', '.') }}
            </p>
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ $expensesByCategory->count() }} categoria(s)</p>
        </div>

        <div class="relative overflow-hidden rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm transition-all hover:shadow-md dark:border-white/10 dark:bg-[#07111f]">
            <div class="absolute -right-6 -top-6 h-24 w-24 rounded-full bg-sky-500/12 blur-2xl"></div>
            <p class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">Saldo do Período</p>
            <p class="mt-3 text-3xl font-black tracking-tight {{ $balance >= 0 ? 'text-sky-700 dark:text-sky-300' : 'text-rose-600 dark:text-rose-300' }}">
                R$ {{ number_format($balance, 2, ',', '.') }}
            </p>
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ $transactionCount }} transação(ões)</p>
        </div>

        <div class="relative overflow-hidden rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm transition-all hover:shadow-md dark:border-white/10 dark:bg-[#07111f]">
            <div class="absolute -right-6 -top-6 h-24 w-24 rounded-full bg-violet-500/12 blur-2xl"></div>
            <p class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">Saldo Disponível</p>
            <p class="mt-3 text-3xl font-black tracking-tight {{ $availableBalance >= 0 ? 'text-emerald-600 dark:text-emerald-300' : 'text-rose-600 dark:text-rose-300' }}">
                R$ {{ number_format($availableBalance, 2, ',', '.') }}
            </p>
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">Acumulado em todo o histórico</p>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
        @if($expensesByCategory->isNotEmpty())
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-[#07111f]">
                <h2 class="text-lg font-black text-zinc-900 dark:text-white">Despesas por Categoria</h2>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Onde o período selecionado concentrou mais saída de caixa.</p>

                <div class="mt-5 space-y-3">
                    @foreach($expensesByCategory as $item)
                        <div class="flex items-center justify-between rounded-2xl border border-zinc-200 bg-zinc-50 p-4 transition hover:bg-zinc-100 dark:border-white/10 dark:bg-white/[0.03] dark:hover:bg-white/[0.05]">
                            <div class="flex items-center gap-3">
                                <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-rose-100 text-rose-600 dark:bg-rose-500/10 dark:text-rose-300">{{ $item['icon'] }}</span>
                                <div>
                                    <p class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $item['category'] }}</p>
                                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $item['count'] }} transação(ões)</p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-lg font-black text-red-600 dark:text-red-400">R$ {{ number_format($item['amount'], 2, ',', '.') }}</p>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $totalExpenses > 0 ? number_format(($item['amount'] / $totalExpenses) * 100, 1) : 0 }}% do total
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if($incomeByCategory->isNotEmpty())
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-[#07111f]">
                <h2 class="text-lg font-black text-zinc-900 dark:text-white">Receitas por Categoria</h2>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Principais fontes de entrada dentro do período analisado.</p>

                <div class="mt-5 space-y-3">
                    @foreach($incomeByCategory as $item)
                        <div class="flex items-center justify-between rounded-2xl border border-zinc-200 bg-zinc-50 p-4 transition hover:bg-zinc-100 dark:border-white/10 dark:bg-white/[0.03] dark:hover:bg-white/[0.05]">
                            <div class="flex items-center gap-3">
                                <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-emerald-100 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $item['icon'] }}</span>
                                <div>
                                    <p class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $item['category'] }}</p>
                                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $item['count'] }} transação(ões)</p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-lg font-black text-emerald-600 dark:text-emerald-400">R$ {{ number_format($item['amount'], 2, ',', '.') }}</p>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $totalIncome > 0 ? number_format(($item['amount'] / $totalIncome) * 100, 1) : 0 }}% do total
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-[#07111f]">
        <div class="mb-5">
            <h2 class="text-lg font-black text-zinc-900 dark:text-white">Todas as Transações</h2>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Lista completa usada para compor o relatório atual.</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="border-b border-zinc-200 bg-zinc-50 dark:border-white/10 dark:bg-white/[0.03]">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500 dark:text-zinc-400">Data</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500 dark:text-zinc-400">Descrição</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500 dark:text-zinc-400">Categoria</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500 dark:text-zinc-400">Tipo</th>
                        <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500 dark:text-zinc-400">Valor</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-white/10">
                    @forelse($transactions as $transaction)
                        <tr class="transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                            <td class="whitespace-nowrap px-6 py-4 text-sm text-zinc-700 dark:text-zinc-300">{{ $transaction->date->format('d/m/Y') }}</td>
                            <td class="px-6 py-4 text-sm text-zinc-900 dark:text-zinc-100">{{ $transaction->description ?? '-' }}</td>
                            <td class="whitespace-nowrap px-6 py-4 text-sm text-zinc-700 dark:text-zinc-300">
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
                            <td class="whitespace-nowrap px-6 py-4">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $transaction->type === 'income' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200' : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-200' }}">
                                    {{ $transaction->type === 'income' ? 'Receita' : 'Despesa' }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-black {{ $transaction->type === 'income' ? 'text-emerald-600 dark:text-emerald-300' : 'text-red-600 dark:text-red-300' }}">
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
