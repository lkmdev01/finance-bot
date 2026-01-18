<?php

use App\Models\SavingsGoal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

new class extends Component {
    public function delete(int $goalId): void
    {
        $goal = Auth::user()->savingsGoals()->findOrFail($goalId);
        $goal->delete();
        session()->flash('message', 'Meta excluída com sucesso!');
    }

    public function removeDeposit(int $goalId, int $depositId): void
    {
        $goal = Auth::user()->savingsGoals()->findOrFail($goalId);
        $deposit = $goal->deposits()->findOrFail($depositId);
        
        // Deletar depósito (o boot() do modelo já remove a transação associada)
        $deposit->delete();
        
        session()->flash('message', 'Depósito removido com sucesso! O valor foi devolvido ao saldo disponível.');
    }

    public function with(): array
    {
        return [
            'goals' => Auth::user()->savingsGoals()
                ->with(['deposits' => fn($q) => $q->orderBy('deposit_date', 'desc')->orderBy('id', 'desc')])
                ->orderBy('is_completed')
                ->orderBy('target_date', 'asc')
                ->get(),
        ];
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Metas de Economia</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Defina e acompanhe suas metas de economia</p>
        </div>
        <flux:button href="{{ route('savings-goals.create') }}" wire:navigate variant="primary">
            Nova Meta
        </flux:button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        @forelse($goals as $goal)
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 {{ $goal->is_completed ? 'opacity-75' : '' }}">
                <div class="flex items-start justify-between mb-4">
                    <div class="flex-1">
                        <h3 class="font-semibold text-lg">{{ $goal->name }}</h3>
                        @if($goal->description)
                            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">{{ $goal->description }}</p>
                        @endif
                        @if($goal->target_date)
                            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-2">
                                Meta até: {{ $goal->target_date->format('d/m/Y') }}
                            </p>
                        @endif
                    </div>
                    <div class="flex items-center gap-2">
                        <flux:button 
                            href="{{ route('savings-goals.deposit', $goal) }}" 
                            wire:navigate
                            variant="primary"
                            size="sm"
                            icon="plus"
                        >
                            Depositar
                        </flux:button>
                        <flux:button 
                            href="{{ route('savings-goals.alerts', $goal) }}" 
                            wire:navigate
                            variant="ghost"
                            size="sm"
                            icon="bell"
                        >
                            Alertas
                        </flux:button>
                        <flux:button 
                            href="{{ route('savings-goals.edit', $goal) }}" 
                            wire:navigate
                            variant="ghost"
                            size="sm"
                            icon="pencil"
                        />
                        <flux:button 
                            wire:click="delete({{ $goal->id }})"
                            wire:confirm="Tem certeza que deseja excluir esta meta?"
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
                            <span class="text-sm text-zinc-600 dark:text-zinc-400">Progresso</span>
                            <span class="text-sm font-semibold">{{ number_format($goal->progress_percentage, 1) }}%</span>
                        </div>
                        <div class="w-full bg-zinc-200 dark:bg-zinc-700 rounded-full h-3">
                            <div 
                                class="h-3 rounded-full transition-all {{ $goal->is_completed ? 'bg-green-500' : 'bg-blue-500' }}"
                                style="width: {{ min(100, $goal->progress_percentage) }}%"
                            ></div>
                        </div>
                    </div>

                    <div class="space-y-1">
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-zinc-600 dark:text-zinc-400">Atual</span>
                            <span class="text-sm font-semibold">R$ {{ number_format($goal->current_amount, 2, ',', '.') }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-zinc-600 dark:text-zinc-400">Meta</span>
                            <span class="text-sm font-semibold">R$ {{ number_format($goal->target_amount, 2, ',', '.') }}</span>
                        </div>
                        <div class="flex items-center justify-between pt-2 border-t border-zinc-200 dark:border-zinc-700">
                            <span class="text-sm font-medium">Restante</span>
                            <span class="text-sm font-bold {{ $goal->remaining_amount > 0 ? 'text-zinc-900 dark:text-zinc-100' : 'text-green-600 dark:text-green-400' }}">
                                R$ {{ number_format($goal->remaining_amount, 2, ',', '.') }}
                            </span>
                        </div>
                    </div>

                    @if($goal->is_completed)
                        <div class="mt-3 p-2 bg-green-100 dark:bg-green-900 rounded-lg text-center">
                            <span class="text-sm font-medium text-green-800 dark:text-green-200">✓ Meta Concluída!</span>
                        </div>
                    @endif

                    @if($goal->deposits->count() > 0)
                        <div class="mt-4 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                            <p class="text-xs font-semibold text-zinc-600 dark:text-zinc-400 mb-2">Depósitos ({{ $goal->deposits->count() }})</p>
                            <div class="space-y-2 max-h-40 overflow-y-auto">
                                @foreach($goal->deposits->take(10) as $deposit)
                                    <div class="flex items-center justify-between text-xs group">
                                        <div class="flex-1">
                                            <p class="font-medium">{{ $deposit->deposit_date->format('d/m/Y') }}</p>
                                            @if($deposit->description)
                                                <p class="text-zinc-500 dark:text-zinc-400">{{ Str::limit($deposit->description, 20) }}</p>
                                            @endif
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <p class="font-semibold text-green-600 dark:text-green-400">+ R$ {{ number_format($deposit->amount, 2, ',', '.') }}</p>
                                            <flux:button 
                                                wire:click="removeDeposit({{ $goal->id }}, {{ $deposit->id }})"
                                                wire:confirm="Tem certeza que deseja remover este depósito? O valor será devolvido ao saldo disponível."
                                                variant="ghost"
                                                size="xs"
                                                icon="trash"
                                                class="opacity-0 group-hover:opacity-100 transition-opacity text-red-600 hover:text-red-700 dark:text-red-400"
                                            />
                                        </div>
                                    </div>
                                @endforeach
                                @if($goal->deposits->count() > 10)
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400 text-center pt-2">
                                        + {{ $goal->deposits->count() - 10 }} depósito(s) anterior(es)
                                    </p>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <div class="col-span-full bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-12 text-center">
                <p class="text-zinc-500 dark:text-zinc-400 mb-4">Nenhuma meta de economia definida.</p>
                <flux:button href="{{ route('savings-goals.create') }}" wire:navigate variant="primary">
                    Criar primeira meta
                </flux:button>
            </div>
        @endforelse
    </div>
</div>
