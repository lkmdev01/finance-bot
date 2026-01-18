<?php

use App\Models\RecurringTransaction;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public function delete(int $recurringId): void
    {
        $recurring = Auth::user()->recurringTransactions()->findOrFail($recurringId);
        $recurring->delete();
        session()->flash('message', 'Transação recorrente excluída com sucesso!');
    }

    public function toggleActive(int $recurringId): void
    {
        $recurring = Auth::user()->recurringTransactions()->findOrFail($recurringId);
        $recurring->update(['is_active' => !$recurring->is_active]);
        session()->flash('message', 'Status atualizado com sucesso!');
    }

    public function with(): array
    {
        return [
            'recurringTransactions' => Auth::user()->recurringTransactions()
                ->with('category')
                ->orderBy('is_active', 'desc')
                ->orderBy('start_date', 'desc')
                ->get(),
        ];
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Transações Recorrentes</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Gerencie transações que se repetem automaticamente</p>
        </div>
        <flux:button href="{{ route('recurring-transactions.create') }}" wire:navigate variant="primary">
            Nova Transação Recorrente
        </flux:button>
    </div>

    <div class="grid grid-cols-1 gap-4">
        @forelse($recurringTransactions as $recurring)
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 {{ !$recurring->is_active ? 'opacity-60' : '' }}">
                <div class="flex items-start justify-between">
                    <div class="flex-1">
                        <div class="flex items-center gap-3 mb-2">
                            <h3 class="font-semibold text-lg">{{ $recurring->description ?? 'Transação Recorrente' }}</h3>
                            <span class="px-2 py-1 text-xs font-medium rounded-full {{ $recurring->is_active ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200' }}">
                                {{ $recurring->is_active ? 'Ativa' : 'Inativa' }}
                            </span>
                            <span class="px-2 py-1 text-xs font-medium rounded-full {{ $recurring->type === 'income' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' }}">
                                {{ $recurring->type === 'income' ? 'Receita' : 'Despesa' }}
                            </span>
                        </div>
                        
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-4">
                            <div>
                                <p class="text-xs text-zinc-600 dark:text-zinc-400">Valor</p>
                                <p class="font-semibold {{ $recurring->type === 'income' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                    {{ $recurring->type === 'income' ? '+' : '-' }}R$ {{ number_format($recurring->amount, 2, ',', '.') }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs text-zinc-600 dark:text-zinc-400">Frequência</p>
                                <p class="font-medium">
                                    @if($recurring->frequency === 'daily')
                                        Diária
                                    @elseif($recurring->frequency === 'weekly')
                                        Semanal
                                    @elseif($recurring->frequency === 'monthly')
                                        Mensal
                                    @elseif($recurring->frequency === 'yearly')
                                        Anual
                                    @endif
                                </p>
                            </div>
                            <div>
                                <p class="text-xs text-zinc-600 dark:text-zinc-400">Início</p>
                                <p class="font-medium">{{ $recurring->start_date->format('d/m/Y') }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-zinc-600 dark:text-zinc-400">Última Processada</p>
                                <p class="font-medium">
                                    {{ $recurring->last_processed_at ? $recurring->last_processed_at->format('d/m/Y') : 'Nunca' }}
                                </p>
                            </div>
                        </div>

                        @if($recurring->category)
                            <div class="mt-3">
                                <p class="text-xs text-zinc-600 dark:text-zinc-400">Categoria</p>
                                <p class="font-medium">{{ $recurring->category->name }}</p>
                            </div>
                        @endif
                    </div>

                    <div class="flex items-center gap-2 ml-4">
                        <flux:button 
                            wire:click="toggleActive({{ $recurring->id }})"
                            variant="ghost"
                            size="sm"
                            icon="{{ $recurring->is_active ? 'pause' : 'play' }}"
                            title="{{ $recurring->is_active ? 'Desativar' : 'Ativar' }}"
                        />
                        <flux:button 
                            href="{{ route('recurring-transactions.edit', $recurring) }}" 
                            wire:navigate
                            variant="ghost"
                            size="sm"
                            icon="pencil"
                        />
                        <flux:button 
                            wire:click="delete({{ $recurring->id }})"
                            wire:confirm="Tem certeza que deseja excluir esta transação recorrente?"
                            variant="ghost"
                            size="sm"
                            icon="trash"
                            class="text-red-600 hover:text-red-700 dark:text-red-400"
                        />
                    </div>
                </div>
            </div>
        @empty
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-12 text-center">
                <p class="text-zinc-500 dark:text-zinc-400 mb-4">Nenhuma transação recorrente definida.</p>
                <flux:button href="{{ route('recurring-transactions.create') }}" wire:navigate variant="primary">
                    Criar primeira transação recorrente
                </flux:button>
            </div>
        @endforelse
    </div>
</div>
