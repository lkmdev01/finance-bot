<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public function delete(int $cardId): void
    {
        $card = Auth::user()->creditCards()->findOrFail($cardId);
        $card->delete();

        session()->flash('message', 'Cartão excluído com sucesso.');
    }

    public function toggleActive(int $cardId): void
    {
        $card = Auth::user()->creditCards()->findOrFail($cardId);
        $card->update(['is_active' => ! $card->is_active]);

        session()->flash('message', 'Status do cartão atualizado com sucesso.');
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

    public function utilizationPct($card): int
    {
        $limit = (float) ($card->credit_limit ?? 0);
        $used = (float) ($card->current_balance ?? 0);

        if ($limit <= 0) {
            return 0;
        }

        $pct = (int) round(($used / $limit) * 100);
        return max(0, min(999, $pct));
    }

    public function nextDueDateLabel($card): string
    {
        if (empty($card->due_day)) {
            return 'Não definido';
        }

        $today = CarbonImmutable::now();
        $dueDay = (int) $card->due_day;
        $base = CarbonImmutable::create($today->year, $today->month, 1)->startOfDay();
        $due = $base->day(min($dueDay, $base->daysInMonth));

        if ($due->lessThan($today->startOfDay())) {
            $nextMonth = $today->addMonthNoOverflow();
            $base = CarbonImmutable::create($nextMonth->year, $nextMonth->month, 1)->startOfDay();
            $due = $base->day(min($dueDay, $base->daysInMonth));
        }

        $days = $today->startOfDay()->diffInDays($due, false);
        $suffix = $days === 0 ? ' (hoje)' : ($days === 1 ? ' (amanhã)' : " (em {$days} dias)");

        return $due->format('d/m/Y') . $suffix;
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Cartões de Crédito</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Acompanhe limite, vencimento e uso da fatura.</p>
        </div>
        <flux:button href="{{ route('credit-cards.create') }}" wire:navigate variant="primary">
            Novo Cartão
        </flux:button>
    </div>

    <div class="grid grid-cols-1 gap-4">
        @forelse($cards as $card)
            @php
                $usedPct = $this->utilizationPct($card);
                $lastTx = $card->transactions->max('date');
            @endphp
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 {{ ! $card->is_active ? 'opacity-60' : '' }}">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex-1">
                        <div class="flex items-center gap-3 mb-3">
                            <h2 class="text-lg font-semibold">{{ $card->name }}</h2>
                            <span class="px-2 py-1 text-xs rounded-full {{ $card->is_active ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200' }}">
                                {{ $card->is_active ? 'Ativo' : 'Inativo' }}
                            </span>
                            @if($card->brand)
                                <span class="px-2 py-1 text-xs rounded-full bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200">
                                    {{ $card->brand }}
                                </span>
                            @endif
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div>
                                <p class="text-xs text-zinc-500">Emissor</p>
                                <p class="font-medium">{{ $card->issuer ?: 'Não informado' }}</p>
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

                        <div class="mt-4">
                            <div class="flex items-center justify-between text-xs text-zinc-500 mb-2">
                                <span>Uso do limite</span>
                                <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $usedPct }}%</span>
                            </div>
                            <div class="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                                <div
                                    class="h-full rounded-full {{ $usedPct >= 90 ? 'bg-red-500' : ($usedPct >= 70 ? 'bg-amber-500' : 'bg-emerald-500') }}"
                                    style="width: {{ min(100, $usedPct) }}%;"
                                ></div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-4">
                            <div>
                                <p class="text-xs text-zinc-500">Fechamento</p>
                                <p class="font-medium">{{ $card->closing_day ?: 'Não definido' }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-zinc-500">Vencimento</p>
                                <p class="font-medium">{{ $this->nextDueDateLabel($card) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-zinc-500">Transações vinculadas</p>
                                <p class="font-medium">{{ $card->transactions->count() }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-zinc-500">Última compra</p>
                                <p class="font-medium">
                                    {{ $lastTx ? \Carbon\Carbon::parse($lastTx)->format('d/m/Y') : 'Nunca' }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <flux:button wire:click="toggleActive({{ $card->id }})" variant="ghost" size="sm" icon="{{ $card->is_active ? 'pause' : 'play' }}" />
                        <flux:button href="{{ route('credit-cards.edit', $card) }}" wire:navigate variant="ghost" size="sm" icon="pencil" />
                        <flux:button wire:click="delete({{ $card->id }})" wire:confirm="Deseja excluir este cartão?" variant="ghost" size="sm" icon="trash" class="text-red-600 hover:text-red-700 dark:text-red-400" />
                    </div>
                </div>
            </div>
        @empty
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-12 text-center">
                <p class="text-zinc-500 dark:text-zinc-400 mb-4">Nenhum cartão cadastrado.</p>
                <flux:button href="{{ route('credit-cards.create') }}" wire:navigate variant="primary">
                    Criar primeiro cartão
                </flux:button>
            </div>
        @endforelse
    </div>
</div>
