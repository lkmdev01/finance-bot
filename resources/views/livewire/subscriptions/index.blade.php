<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public function delete(int $subscriptionId): void
    {
        $subscription = Auth::user()->subscriptions()->findOrFail($subscriptionId);
        $subscription->delete();

        session()->flash('message', 'Assinatura excluida com sucesso.');
    }

    public function toggleActive(int $subscriptionId): void
    {
        $subscription = Auth::user()->subscriptions()->findOrFail($subscriptionId);
        $subscription->update(['is_active' => ! $subscription->is_active]);

        session()->flash('message', 'Status da assinatura atualizado.');
    }

    public function markAsPaid(int $subscriptionId): void
    {
        $subscription = Auth::user()->subscriptions()->findOrFail($subscriptionId);
        $subscription->markAsPaid();

        session()->flash('message', 'Assinatura marcada como paga e transacao registrada.');
    }

    public function with(): array
    {
        return [
            'subscriptions' => Auth::user()->subscriptions()
                ->with(['bankAccount', 'creditCard', 'category'])
                ->orderBy('is_active', 'desc')
                ->orderBy('next_due_date')
                ->get(),
        ];
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Assinaturas e Contas Recorrentes</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Gerencie vencimentos, fonte de pagamento e registro automatico.</p>
        </div>
        <flux:button href="{{ route('subscriptions.create') }}" wire:navigate variant="primary">
            Nova Assinatura
        </flux:button>
    </div>

    <div class="grid grid-cols-1 gap-4">
        @forelse($subscriptions as $subscription)
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 {{ ! $subscription->is_active ? 'opacity-60' : '' }}">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex-1">
                        <div class="flex items-center gap-3 mb-3">
                            <h2 class="text-lg font-semibold">{{ $subscription->name }}</h2>
                            <span class="px-2 py-1 text-xs rounded-full {{ $subscription->is_active ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200' }}">
                                {{ $subscription->is_active ? 'Ativa' : 'Inativa' }}
                            </span>
                            <span class="px-2 py-1 text-xs rounded-full {{ $subscription->is_due ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' : 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' }}">
                                {{ $subscription->is_due ? 'Vencida ou vence hoje' : 'Em dia' }}
                            </span>
                            @if($subscription->auto_record)
                                <span class="px-2 py-1 text-xs rounded-full bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200">
                                    Auto registro
                                </span>
                            @endif
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                            <div>
                                <p class="text-xs text-zinc-500">Valor</p>
                                <p class="font-semibold text-red-600 dark:text-red-400">R$ {{ number_format($subscription->amount, 2, ',', '.') }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-zinc-500">Ciclo</p>
                                <p class="font-medium">{{ ucfirst($subscription->billing_cycle) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-zinc-500">Vencimento</p>
                                <p class="font-medium">{{ $subscription->next_due_date?->format('d/m/Y') ?: 'Nao definido' }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-zinc-500">Ultimo pagamento</p>
                                <p class="font-medium">{{ $subscription->last_paid_at?->format('d/m/Y') ?: 'Nunca' }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-zinc-500">Fonte</p>
                                <p class="font-medium">{{ $subscription->source_label }}</p>
                            </div>
                        </div>

                        @if($subscription->category)
                            <div class="mt-4">
                                <p class="text-xs text-zinc-500">Categoria</p>
                                <p class="font-medium">{{ $subscription->category->name }}</p>
                            </div>
                        @endif
                    </div>

                    <div class="flex items-center gap-2">
                        <flux:button wire:click="markAsPaid({{ $subscription->id }})" variant="ghost" size="sm" icon="check" title="Marcar como paga" />
                        <flux:button wire:click="toggleActive({{ $subscription->id }})" variant="ghost" size="sm" icon="{{ $subscription->is_active ? 'pause' : 'play' }}" />
                        <flux:button href="{{ route('subscriptions.edit', $subscription) }}" wire:navigate variant="ghost" size="sm" icon="pencil" />
                        <flux:button wire:click="delete({{ $subscription->id }})" wire:confirm="Deseja excluir esta assinatura?" variant="ghost" size="sm" icon="trash" class="text-red-600 hover:text-red-700 dark:text-red-400" />
                    </div>
                </div>
            </div>
        @empty
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-12 text-center">
                <p class="text-zinc-500 dark:text-zinc-400 mb-4">Nenhuma assinatura cadastrada.</p>
                <flux:button href="{{ route('subscriptions.create') }}" wire:navigate variant="primary">
                    Criar primeira assinatura
                </flux:button>
            </div>
        @endforelse
    </div>
</div>
