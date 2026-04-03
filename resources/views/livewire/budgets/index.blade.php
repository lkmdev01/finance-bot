<?php

use App\Models\Budget;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public string $period = 'monthly';
    public int $year;
    public ?int $month = null;

    public function mount(): void
    {
        $this->year = now()->year;
        $this->month = now()->month;
    }

    public function delete(int $budgetId): void
    {
        $budget = Auth::user()->budgets()->findOrFail($budgetId);
        $budget->delete();
        session()->flash('message', 'OrÃ§amento excluÃ­do com sucesso!');
    }

    public function with(): array
    {
        $query = Auth::user()->budgets()
            ->with('category')
            ->where('period', $this->period)
            ->where('year', $this->year);

        if ($this->period === 'monthly' && $this->month) {
            $query->where('month', $this->month);
        }

        return [
            'budgets' => $query->get(),
            'categories' => Auth::user()->categories()
                ->where('type', 'expense')
                ->orderBy('name')
                ->get(),
        ];
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">OrÃ§amentos</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Defina limites de gastos por categoria</p>
        </div>
        <flux:button href="{{ route('budgets.create') }}" wire:navigate variant="primary">
            Novo OrÃ§amento
        </flux:button>
    </div>

    <!-- Filtros -->
    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <flux:select wire:model.live="period" label="PerÃ­odo">
                <option value="monthly">Mensal</option>
                <option value="yearly">Anual</option>
            </flux:select>

            <flux:input type="number" wire:model.live="year" label="Ano" min="2020" max="2100" />

            @if($period === 'monthly')
                <flux:select wire:model.live="month" label="MÃªs">
                    @for($m = 1; $m <= 12; $m++)
                        <option value="{{ $m }}">{{ \Carbon\Carbon::create(null, $m, 1)->locale('pt_BR')->monthName }}</option>
                    @endfor
                </flux:select>
            @endif
        </div>
    </div>

    <!-- Lista de OrÃ§amentos -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        @forelse($budgets as $budget)
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center gap-3">
                        @if($budget->category->icon)
                            <span class="text-2xl">{{ $budget->category->icon }}</span>
                        @endif
                        <div>
                            <h3 class="font-semibold">{{ $budget->category->name }}</h3>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $budget->period === 'monthly' ? 'Mensal' : 'Anual' }} - {{ $budget->year }}
                                @if($budget->month)
                                    - {{ \Carbon\Carbon::create($budget->year, $budget->month, 1)->locale('pt_BR')->monthName }}
                                @endif
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <flux:button 
                            href="{{ route('budgets.edit', $budget) }}" 
                            wire:navigate
                            variant="ghost"
                            size="sm"
                            icon="pencil"
                        />
                        <flux:button 
                            wire:click="delete({{ $budget->id }})"
                            wire:confirm="Tem certeza que deseja excluir este orÃ§amento?"
                            variant="ghost"
                            size="sm"
                            icon="trash"
                            class="text-red-600 hover:text-red-700 dark:text-red-400"
                        />
                    </div>
                </div>

                <div class="space-y-3">
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-sm text-zinc-600 dark:text-zinc-400">OrÃ§ado</span>
                            <span class="text-sm font-semibold">R$ {{ number_format($budget->amount, 2, ',', '.') }}</span>
                        </div>
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-sm text-zinc-600 dark:text-zinc-400">Gasto</span>
                            <span class="text-sm font-semibold text-red-600 dark:text-red-400">
                                R$ {{ number_format($budget->spent, 2, ',', '.') }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-zinc-600 dark:text-zinc-400">Restante</span>
                            <span class="text-sm font-semibold {{ $budget->remaining >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                R$ {{ number_format($budget->remaining, 2, ',', '.') }}
                            </span>
                        </div>
                    </div>

                    <div class="w-full bg-zinc-200 dark:bg-zinc-700 rounded-full h-3">
                        <div 
                            class="h-3 rounded-full transition-all {{ $budget->percentage_used > 100 ? 'bg-red-500' : ($budget->percentage_used > 80 ? 'bg-yellow-500' : 'bg-green-500') }}"
                            style="width: {{ min(100, $budget->percentage_used) }}%"
                        ></div>
                    </div>
                    <p class="text-xs text-center text-zinc-500 dark:text-zinc-400">
                        {{ number_format($budget->percentage_used, 1) }}% utilizado
                    </p>
                </div>
            </div>
        @empty
            <div class="col-span-full bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-12 text-center">
                <p class="text-zinc-500 dark:text-zinc-400 mb-4">Nenhum orÃ§amento encontrado para este perÃ­odo.</p>
                <flux:button href="{{ route('budgets.create') }}" wire:navigate variant="primary">
                    Criar primeiro orÃ§amento
                </flux:button>
            </div>
        @endforelse
    </div>
</div>
