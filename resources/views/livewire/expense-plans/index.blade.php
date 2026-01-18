<?php

use App\Models\ExpensePlan;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public function delete(int $planId): void
    {
        $plan = Auth::user()->expensePlans()->findOrFail($planId);
        $plan->delete();
        session()->flash('message', 'Plano de despesas excluído com sucesso!');
    }

    public function toggleActive(int $planId): void
    {
        $plan = Auth::user()->expensePlans()->findOrFail($planId);
        $plan->update(['is_active' => ! $plan->is_active]);
        session()->flash('message', 'Status do plano atualizado!');
    }

    public function with(): array
    {
        return [
            'plans' => Auth::user()->expensePlans()
                ->orderBy('start_date', 'desc')
                ->orderBy('created_at', 'desc')
                ->paginate(15),
        ];
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Planejamento de Gastos</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Gerencie seus planos de despesas</p>
        </div>
        <flux:button href="{{ route('expense-plans.create') }}" wire:navigate variant="primary">
            Novo Plano
        </flux:button>
    </div>

    @if($plans->count() > 0)
        <div class="grid grid-cols-1 gap-4">
            @foreach($plans as $plan)
                <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <div class="flex items-center gap-3 mb-2">
                                <h3 class="text-lg font-semibold">{{ $plan->name }}</h3>
                                @if($plan->is_active)
                                    <span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                        Ativo
                                    </span>
                                @else
                                    <span class="px-2 py-1 text-xs font-medium rounded-full bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200">
                                        Inativo
                                    </span>
                                @endif
                                @if($plan->is_exceeded)
                                    <span class="px-2 py-1 text-xs font-medium rounded-full bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">
                                        Excedido
                                    </span>
                                @endif
                            </div>

                            @if($plan->description)
                                <p class="text-sm text-zinc-600 dark:text-zinc-400 mb-4">{{ $plan->description }}</p>
                            @endif

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                                <div>
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-1">Período</p>
                                    <p class="text-sm font-medium">
                                        {{ $plan->start_date->format('d/m/Y') }} - {{ $plan->end_date->format('d/m/Y') }}
                                    </p>
                                </div>
                                <div>
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-1">Planejado</p>
                                    <p class="text-sm font-medium">R$ {{ number_format($plan->planned_amount, 2, ',', '.') }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-1">Gasto</p>
                                    <p class="text-sm font-medium {{ $plan->is_exceeded ? 'text-red-600 dark:text-red-400' : '' }}">
                                        R$ {{ number_format($plan->spent_amount, 2, ',', '.') }}
                                    </p>
                                </div>
                            </div>

                            <div class="mb-4">
                                <div class="flex items-center justify-between text-xs text-zinc-500 dark:text-zinc-400 mb-1">
                                    <span>Progresso</span>
                                    <span>{{ number_format($plan->progress_percentage, 1) }}%</span>
                                </div>
                                <div class="w-full bg-zinc-200 dark:bg-zinc-700 rounded-full h-2">
                                    <div 
                                        class="h-2 rounded-full transition-all {{ $plan->is_exceeded ? 'bg-red-600 dark:bg-red-500' : 'bg-blue-600 dark:bg-blue-500' }}"
                                        style="width: {{ min(100, $plan->progress_percentage) }}%"
                                    ></div>
                                </div>
                            </div>

                            <div>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-1">Restante</p>
                                <p class="text-sm font-semibold {{ $plan->remaining_amount < 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                                    R$ {{ number_format($plan->remaining_amount, 2, ',', '.') }}
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 ml-4">
                            <flux:button 
                                wire:click="toggleActive({{ $plan->id }})"
                                variant="ghost"
                                size="sm"
                            >
                                {{ $plan->is_active ? 'Desativar' : 'Ativar' }}
                            </flux:button>
                            <flux:button 
                                href="{{ route('expense-plans.edit', $plan) }}"
                                wire:navigate
                                variant="ghost"
                                size="sm"
                            >
                                Editar
                            </flux:button>
                            <flux:button 
                                wire:click="delete({{ $plan->id }})"
                                wire:confirm="Tem certeza que deseja excluir este plano?"
                                variant="ghost"
                                size="sm"
                            >
                                Excluir
                            </flux:button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4">
            {{ $plans->links() }}
        </div>
    @else
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-12 text-center">
            <p class="text-zinc-500 dark:text-zinc-400 mb-4">Nenhum plano de despesas criado ainda.</p>
            <flux:button href="{{ route('expense-plans.create') }}" wire:navigate variant="primary">
                Criar Primeiro Plano
            </flux:button>
        </div>
    @endif
</div>
