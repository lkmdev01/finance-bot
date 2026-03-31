<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public function delete(int $cardId): void
    {
        $card = Auth::user()->creditCards()->findOrFail($cardId);
        $card->delete();

        session()->flash('message', 'Cartao excluido com sucesso.');
    }

    public function toggleActive(int $cardId): void
    {
        $card = Auth::user()->creditCards()->findOrFail($cardId);
        $card->update(['is_active' => ! $card->is_active]);

        session()->flash('message', 'Status do cartao atualizado com sucesso.');
    }

    public function with(): array
    {
        return [
            'cards' => Auth::user()->creditCards()
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
            <h1 class="text-2xl font-bold">Cartoes de Credito</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Acompanhe limite, vencimento e saldo utilizado.</p>
        </div>
        <flux:button href="{{ route('credit-cards.create') }}" wire:navigate variant="primary">
            Novo Cartao
        </flux:button>
    </div>

    <div class="grid grid-cols-1 gap-4">
        @forelse($cards as $card)
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 {{ ! $card->is_active ? 'opacity-60' : '' }}">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex-1">
                        <div class="flex items-center gap-3 mb-3">
                            <h2 class="text-lg font-semibold">{{ $card->name }}</h2>
                            <span class="px-2 py-1 text-xs rounded-full {{ $card->is_active ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200' }}">
                                {{ $card->is_active ? 'Ativo' : 'Inativo' }}
                            </span>
                            @if($card->brand)
                                <span class="px-2 py-1 text-xs rounded-full bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200">
                                    {{ $card->brand }}
                                </span>
                            @endif
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div>
                                <p class="text-xs text-zinc-500">Emissor</p>
                                <p class="font-medium">{{ $card->issuer ?: 'Nao informado' }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-zinc-500">Limite</p>
                                <p class="font-medium">R$ {{ number_format($card->credit_limit, 2, ',', '.') }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-zinc-500">Utilizado</p>
                                <p class="font-semibold text-red-600 dark:text-red-400">R$ {{ number_format($card->current_balance, 2, ',', '.') }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-zinc-500">Disponivel</p>
                                <p class="font-semibold {{ $card->available_limit >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                    R$ {{ number_format($card->available_limit, 2, ',', '.') }}
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                            <div>
                                <p class="text-xs text-zinc-500">Fechamento</p>
                                <p class="font-medium">{{ $card->closing_day ?: 'Nao definido' }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-zinc-500">Vencimento</p>
                                <p class="font-medium">{{ $card->due_day ?: 'Nao definido' }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-zinc-500">Transacoes vinculadas</p>
                                <p class="font-medium">{{ $card->transactions->count() }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <flux:button wire:click="toggleActive({{ $card->id }})" variant="ghost" size="sm" icon="{{ $card->is_active ? 'pause' : 'play' }}" />
                        <flux:button href="{{ route('credit-cards.edit', $card) }}" wire:navigate variant="ghost" size="sm" icon="pencil" />
                        <flux:button wire:click="delete({{ $card->id }})" wire:confirm="Deseja excluir este cartao?" variant="ghost" size="sm" icon="trash" class="text-red-600 hover:text-red-700 dark:text-red-400" />
                    </div>
                </div>
            </div>
        @empty
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-12 text-center">
                <p class="text-zinc-500 dark:text-zinc-400 mb-4">Nenhum cartao cadastrado.</p>
                <flux:button href="{{ route('credit-cards.create') }}" wire:navigate variant="primary">
                    Criar primeiro cartao
                </flux:button>
            </div>
        @endforelse
    </div>
</div>
