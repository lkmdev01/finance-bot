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

<div class="space-y-6 p-6">
    <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
            <h1 class="text-2xl font-bold">Projeções Financeiras</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Visualize projeções de saldo futuro baseadas em suas transações históricas e recorrentes
            </p>
        </div>

        <div class="flex items-end gap-3">
            <flux:field>
                <flux:label>Período (meses)</flux:label>
                <flux:input type="number" wire:model.live="months" min="3" max="24" class="w-24" />
            </flux:field>

            <flux:button wire:click="generateProjections" variant="primary">
                Atualizar Projeções
            </flux:button>
        </div>
    </div>

    <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-900/20">
        <div class="flex items-start gap-3">
            <div class="mt-0.5 flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900">
                <span class="text-sm text-blue-600 dark:text-blue-400">&#8505;</span>
            </div>

            <div class="flex-1">
                <h3 class="mb-2 font-semibold text-blue-900 dark:text-blue-200">Como funcionam as projeções?</h3>
                <ul class="list-inside list-disc space-y-1 text-sm text-blue-800 dark:text-blue-300">
                    <li><strong>Receitas projetadas:</strong> Soma das transações recorrentes ativas + 30% da média mensal dos últimos 6 meses</li>
                    <li><strong>Despesas projetadas:</strong> Soma das transações recorrentes ativas + 70% da média mensal dos últimos 6 meses</li>
                    <li><strong>Saldo projetado:</strong> Calculado mês a mês, considerando o saldo anterior + receitas - despesas</li>
                </ul>
                <p class="mt-2 text-xs text-blue-700 dark:text-blue-400">
                    As projeções são estimativas baseadas em padrões históricos e podem variar conforme suas transações reais.
                </p>
            </div>
        </div>
    </div>

    <div class="rounded-xl border border-zinc-700 bg-gradient-to-br from-zinc-900 to-zinc-950 p-6 dark:from-zinc-800 dark:to-zinc-900">
        <p class="mb-2 text-sm text-zinc-400">Saldo Atual</p>
        <p class="text-3xl font-bold {{ $currentBalance >= 0 ? 'text-green-400' : 'text-red-400' }}">
            R$ {{ number_format($currentBalance, 2, ',', '.') }}
        </p>
        <p class="mt-2 text-xs text-zinc-500">Este é o ponto de partida para as projeções futuras</p>
    </div>

    @if($projections->count() > 0)
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-semibold">Evolução Projetada do Saldo</h2>
                <div class="flex items-center gap-4 text-xs text-zinc-500 dark:text-zinc-400">
                    <div class="flex items-center gap-2">
                        <div class="h-3 w-3 rounded-full bg-blue-500"></div>
                        <span>Saldo</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="h-3 w-3 rounded-full bg-green-500"></div>
                        <span>Receitas</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="h-3 w-3 rounded-full bg-red-500"></div>
                        <span>Despesas</span>
                    </div>
                </div>
            </div>

            <div class="h-80" x-data="{
                init() {
                    const ctx = this.$el.getContext('2d');
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: {{ json_encode($chartData->pluck('date')) }},
                            datasets: [{
                                label: 'Saldo Projetado',
                                data: {{ json_encode($chartData->pluck('balance')) }},
                                borderColor: 'rgb(59, 130, 246)',
                                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                                tension: 0.4,
                                fill: true
                            }, {
                                label: 'Receitas Projetadas',
                                data: {{ json_encode($chartData->pluck('income')) }},
                                borderColor: 'rgb(34, 197, 94)',
                                backgroundColor: 'rgba(34, 197, 94, 0.1)',
                                tension: 0.4,
                                fill: true
                            }, {
                                label: 'Despesas Projetadas',
                                data: {{ json_encode($chartData->pluck('expenses')) }},
                                borderColor: 'rgb(239, 68, 68)',
                                backgroundColor: 'rgba(239, 68, 68, 0.1)',
                                tension: 0.4,
                                fill: true
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: true,
                                    position: 'top'
                                },
                                tooltip: {
                                    mode: 'index',
                                    intersect: false,
                                    callbacks: {
                                        label: function(context) {
                                            return context.dataset.label + ': R$ ' + context.parsed.y.toFixed(2).replace('.', ',');
                                        }
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: false,
                                    ticks: {
                                        callback: function(value) {
                                            return 'R$ ' + value.toFixed(2).replace('.', ',');
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            }">
                <canvas></canvas>
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="mb-4 text-lg font-semibold">Detalhes das Projeções</h2>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Mês</th>
                            <th class="px-6 py-3 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Receitas Projetadas</th>
                            <th class="px-6 py-3 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Despesas Projetadas</th>
                            <th class="px-6 py-3 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Saldo Projetado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach($projections as $projection)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                <td class="whitespace-nowrap px-6 py-4 text-sm font-medium">
                                    {{ \Carbon\Carbon::parse($projection->projection_date)->locale('pt_BR')->translatedFormat('F \\d\\e Y') }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-right text-sm text-green-600 dark:text-green-400">
                                    R$ {{ number_format($projection->projected_income, 2, ',', '.') }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-right text-sm text-red-600 dark:text-red-400">
                                    R$ {{ number_format($projection->projected_expenses, 2, ',', '.') }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-semibold {{ $projection->projected_balance >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                    R$ {{ number_format($projection->projected_balance, 2, ',', '.') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="rounded-xl border border-zinc-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <p class="mb-4 text-zinc-500 dark:text-zinc-400">Nenhuma projeção disponível. Clique em "Atualizar Projeções" para gerar.</p>
            <flux:button wire:click="generateProjections" variant="primary">
                Gerar Projeções
            </flux:button>
        </div>
    @endif
</div>
