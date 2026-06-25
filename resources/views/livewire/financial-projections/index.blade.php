<?php

use App\Services\FinancialProjectionService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public int $months = 12;

    public function mount(): void
    {
        $this->generateProjections();
    }

    public function updatedMonths(): void
    {
        $this->generateProjections();
    }

    public function generateProjections(): void
    {
        $service = app(FinancialProjectionService::class);
        $service->generateProjections(Auth::user(), $this->months);
    }

    public function with(): array
    {
        $projections = Auth::user()->financialProjections()
            ->orderBy('projection_date')
            ->limit($this->months)
            ->get();

        $chartData = $projections->map(function ($projection) {
            return [
                'date' => $projection->projection_date->format('M/Y'),
                'balance' => (float) $projection->projected_balance,
                'income' => (float) $projection->projected_income,
                'expenses' => (float) $projection->projected_expenses,
            ];
        });

        return [
            'projections' => $projections,
            'chartData' => $chartData,
            'currentBalance' => $this->getCurrentBalance(),
        ];
    }

    protected function getCurrentBalance(): float
    {
        $user = Auth::user();
        $totalIncome = (float) $user->transactions()
            ->where('type', 'income')
            ->sum('amount');

        $allExpenses = $user->transactions()
            ->where('type', 'expense')
            ->get();

        $expensesWithoutSavings = $allExpenses->filter(function ($transaction) {
            $metadata = $transaction->metadata ?? [];

            return ! isset($metadata['savings_goal_deposit_id']);
        });

        $totalExpenses = (float) $expensesWithoutSavings->sum('amount');
        $totalSavings = (float) $user->savingsGoals()
            ->with('deposits')
            ->get()
            ->sum(fn ($goal) => $goal->deposits->sum('amount'));

        return $totalIncome - $totalExpenses - $totalSavings;
    }
}; ?>

<div class="space-y-6 px-4 py-5 sm:p-6">
    <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-sky-500 dark:text-sky-300">Planejamento</p>
            <h1 class="mt-2 text-3xl font-black tracking-tight text-zinc-900 dark:text-white">Projeções Financeiras</h1>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-zinc-500 dark:text-zinc-400">
                Visualize a tendência do seu saldo, das receitas e das despesas futuras com base no histórico já registrado.
            </p>
        </div>

        <div class="flex flex-wrap items-end gap-3">
            <flux:field>
                <flux:label>Período (meses)</flux:label>
                <flux:input type="number" wire:model.live="months" min="3" max="24" class="w-24" />
            </flux:field>

            <flux:button wire:click="generateProjections" variant="primary">
                Atualizar projeções
            </flux:button>
        </div>
    </div>

    <div class="rounded-[2rem] border border-sky-900/40 bg-slate-950 p-5 text-slate-100 shadow-[0_24px_80px_rgba(2,6,23,0.34)]">
        <div class="grid gap-4 lg:grid-cols-[1.2fr_0.8fr]">
            <div class="rounded-3xl border border-white/10 bg-white/5 p-5">
                <div class="flex items-start gap-4">
                    <div class="mt-1 flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-sky-500/15 text-sky-300">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 3v18" />
                            <path d="M7 8.5c0-1.9 2.1-3.5 5-3.5s5 1.6 5 3.5-2.1 3.5-5 3.5-5 1.6-5 3.5 2.1 3.5 5 3.5 5-1.6 5-3.5" />
                        </svg>
                    </div>
                    <div class="flex-1">
                        <h2 class="text-base font-bold text-white">Como as projeções são calculadas</h2>
                        <ul class="mt-3 space-y-2 text-sm leading-6 text-slate-300">
                            <li><strong class="text-white">Receitas projetadas:</strong> recorrências ativas + 30% da média mensal dos últimos 6 meses.</li>
                            <li><strong class="text-white">Despesas projetadas:</strong> recorrências ativas + 70% da média mensal dos últimos 6 meses.</li>
                            <li><strong class="text-white">Saldo projetado:</strong> saldo anterior + receitas projetadas - despesas projetadas.</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="rounded-3xl border border-white/10 bg-gradient-to-br from-sky-500/12 via-sky-500/5 to-transparent p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-sky-200">Ponto de partida</p>
                <p class="mt-3 text-sm text-slate-300">Saldo atual considerado na simulação</p>
                <p class="mt-4 text-4xl font-black tracking-tight {{ $currentBalance >= 0 ? 'text-emerald-300' : 'text-rose-300' }}">
                    R$ {{ number_format($currentBalance, 2, ',', '.') }}
                </p>
                <p class="mt-3 text-xs leading-5 text-slate-400">
                    As projeções são estimativas. O cenário real muda conforme novos lançamentos, recorrências e depósitos em metas.
                </p>
            </div>
        </div>
    </div>

    @if($projections->count() > 0)
        <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm transition-all hover:shadow-md dark:border-white/10 dark:bg-[#07111f] sm:p-6">
            <div class="mb-6 flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                <div>
                    <h2 class="text-lg font-black text-zinc-900 dark:text-white">Evolução projetada do saldo</h2>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Comparação entre saldo, receitas e despesas ao longo do horizonte selecionado.</p>
                </div>

                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs font-medium text-zinc-500 dark:text-zinc-400">
                    <div class="flex items-center gap-2">
                        <div class="h-3 w-3 rounded-full bg-blue-500 shadow-[0_0_10px_rgba(59,130,246,0.45)]"></div>
                        <span>Saldo</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="h-3 w-3 rounded-full bg-emerald-500 shadow-[0_0_10px_rgba(16,185,129,0.45)]"></div>
                        <span>Receitas</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="h-3 w-3 rounded-full bg-red-500 shadow-[0_0_10px_rgba(239,68,68,0.45)]"></div>
                        <span>Despesas</span>
                    </div>
                </div>
            </div>

            <div class="-mx-2 overflow-x-auto overscroll-x-contain px-2 pb-2 sm:mx-0 sm:overflow-visible sm:px-0 sm:pb-0">
                <div
                class="relative min-w-[620px] sm:min-w-0"
                style="min-height: 340px;"
                wire:key="financial-projections-chart-{{ $months }}-{{ md5($chartData->toJson()) }}"
                x-data="{
                    chart: null,
                    isMobile: false,
                    init() {
                        this.isMobile = window.matchMedia('(max-width: 640px)').matches;
                        this.renderChart();
                    },
                    renderChart() {
                        const isDark = document.documentElement.classList.contains('dark');

                        if (this.chart) {
                            this.chart.destroy();
                        }

                        const options = {
                            chart: {
                                type: 'area',
                                height: this.isMobile ? 300 : 340,
                                toolbar: { show: false },
                                background: 'transparent',
                                fontFamily: 'inherit',
                                parentHeightOffset: 0,
                            },
                            theme: { mode: isDark ? 'dark' : 'light' },
                            colors: ['#3b82f6', '#10b981', '#ef4444'],
                            series: [
                                { name: 'Saldo Projetado', data: @js($chartData->pluck('balance')->values()) },
                                { name: 'Receitas Projetadas', data: @js($chartData->pluck('income')->values()) },
                                { name: 'Despesas Projetadas', data: @js($chartData->pluck('expenses')->values()) },
                            ],
                            xaxis: {
                                categories: @js($chartData->pluck('date')->values()),
                                tickAmount: this.isMobile ? 6 : undefined,
                                labels: {
                                    rotate: this.isMobile ? -35 : -45,
                                    hideOverlappingLabels: true,
                                    trim: true,
                                    style: {
                                        colors: isDark ? '#94a3b8' : '#64748b',
                                        fontSize: this.isMobile ? '11px' : '12px',
                                    },
                                },
                                axisBorder: { show: false },
                                axisTicks: { show: false },
                                tooltip: { enabled: false },
                            },
                            yaxis: {
                                labels: {
                                    offsetX: this.isMobile ? -8 : 0,
                                    style: {
                                        colors: isDark ? '#94a3b8' : '#64748b',
                                        fontSize: this.isMobile ? '11px' : '12px',
                                    },
                                    formatter: (value) => 'R$ ' + Number(value).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
                                },
                            },
                            dataLabels: { enabled: false },
                            stroke: { curve: 'smooth', width: 3 },
                            fill: {
                                type: 'gradient',
                                gradient: {
                                    shadeIntensity: 1,
                                    opacityFrom: 0.32,
                                    opacityTo: 0.02,
                                    stops: [0, 90, 100],
                                },
                            },
                            tooltip: {
                                theme: isDark ? 'dark' : 'light',
                                shared: true,
                                intersect: false,
                                y: {
                                    formatter: (val) => 'R$ ' + Number(val).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
                                },
                            },
                            legend: {
                                show: ! this.isMobile,
                                position: 'top',
                                horizontalAlign: 'left',
                                labels: { colors: isDark ? '#cbd5e1' : '#475569' },
                            },
                            grid: {
                                borderColor: isDark ? 'rgba(255,255,255,0.06)' : 'rgba(15,23,42,0.08)',
                                strokeDashArray: 4,
                            },
                        };

                        this.chart = new window.ApexCharts(this.$refs.chart, options);
                        this.chart.render();
                    }
                }"
            >
                <div x-ref="chart" class="-ml-4" wire:ignore></div>
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition-all hover:shadow-md dark:border-white/10 dark:bg-[#07111f]">
            <div class="mb-5 flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-black text-zinc-900 dark:text-white">Detalhes das projeções</h2>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Leitura mês a mês do cenário projetado.</p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="border-b border-zinc-200 bg-zinc-50 dark:border-white/10 dark:bg-white/[0.03]">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500 dark:text-zinc-400">Mês</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500 dark:text-zinc-400">Receitas</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500 dark:text-zinc-400">Despesas</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500 dark:text-zinc-400">Saldo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-white/10">
                        @foreach($projections as $projection)
                            <tr class="transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                                <td class="whitespace-nowrap px-6 py-4 text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ \Carbon\Carbon::parse($projection->projection_date)->locale('pt_BR')->translatedFormat('F \\d\\e Y') }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-medium text-emerald-600 dark:text-emerald-400">
                                    R$ {{ number_format($projection->projected_income, 2, ',', '.') }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-medium text-red-600 dark:text-red-400">
                                    R$ {{ number_format($projection->projected_expenses, 2, ',', '.') }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-black {{ $projection->projected_balance >= 0 ? 'text-sky-700 dark:text-sky-300' : 'text-rose-600 dark:text-rose-300' }}">
                                    R$ {{ number_format($projection->projected_balance, 2, ',', '.') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="rounded-2xl border border-zinc-200 bg-white p-12 text-center shadow-sm dark:border-white/10 dark:bg-[#07111f]">
            <p class="mb-4 text-zinc-500 dark:text-zinc-400">Nenhuma projeção disponível. Clique em "Atualizar projeções" para gerar.</p>
            <flux:button wire:click="generateProjections" variant="primary">
                Gerar projeções
            </flux:button>
        </div>
    @endif
</div>
