<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public function delete(int $accountId): void
    {
        $account = Auth::user()->bankAccounts()->findOrFail($accountId);
        $account->delete();

        session()->flash('message', 'Conta bancária excluída com sucesso.');
    }

    public function toggleActive(int $accountId): void
    {
        $account = Auth::user()->bankAccounts()->findOrFail($accountId);
        $account->update(['is_active' => ! $account->is_active]);

        session()->flash('message', 'Status da conta atualizado com sucesso.');
    }

    public function with(): array
    {
        return [
            'accounts' => Auth::user()->bankAccounts()
                ->with('transactions')
                ->orderBy('is_active', 'desc')
                ->orderBy('name')
                ->get(),
        ];
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Contas Bancárias</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Gerencie contas correntes, poupanças e carteiras.</p>
        </div>
        <flux:button href="{{ route('bank-accounts.create') }}" wire:navigate variant="primary">
            Nova Conta
        </flux:button>
    </div>

    <div class="grid grid-cols-1 gap-4">
        @forelse($accounts as $account)
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 {{ ! $account->is_active ? 'opacity-60' : '' }}">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex-1">
                        <div class="flex items-center gap-3 mb-3">
                            <h2 class="text-lg font-semibold">{{ $account->name }}</h2>
                            <span class="px-2 py-1 text-xs rounded-full {{ $account->is_active ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200' }}">
                                {{ $account->is_active ? 'Ativa' : 'Inativa' }}
                            </span>
                            <span class="px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                {{ ucfirst($account->type) }}
                            </span>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div>
                                <p class="text-xs text-zinc-500">Instituição</p>
                                <p class="font-medium">{{ $account->institution ?: 'Não informada' }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-zinc-500">Saldo inicial</p>
                                <p class="font-medium">R$ {{ number_format($account->opening_balance, 2, ',', '.') }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-zinc-500">Saldo atual</p>
                                <p class="font-semibold {{ $account->current_balance >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                    R$ {{ number_format($account->current_balance, 2, ',', '.') }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs text-zinc-500">Transações</p>
                                <p class="font-medium">{{ $account->transactions->count() }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <flux:button wire:click="toggleActive({{ $account->id }})" variant="ghost" size="sm" icon="{{ $account->is_active ? 'pause' : 'play' }}" />
                        <flux:button href="{{ route('bank-accounts.edit', $account) }}" wire:navigate variant="ghost" size="sm" icon="pencil" />
                        <flux:button wire:click="delete({{ $account->id }})" wire:confirm="Deseja excluir esta conta?" variant="ghost" size="sm" icon="trash" class="text-red-600 hover:text-red-700 dark:text-red-400" />
                    </div>
                </div>
            </div>
        @empty
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-12 text-center">
                <p class="text-zinc-500 dark:text-zinc-400 mb-4">Nenhuma conta bancária cadastrada.</p>
                <flux:button href="{{ route('bank-accounts.create') }}" wire:navigate variant="primary">
                    Criar primeira conta
                </flux:button>
            </div>
        @endforelse
    </div>
</div>
