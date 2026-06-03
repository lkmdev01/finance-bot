<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public function delete(int $cardId): void
    {
        $card = Auth::user()->creditCards()->findOrFail($cardId);
        $card->delete();

        session()->flash('message', 'CartÃ£o excluÃ­do com sucesso.');
    }

    public function toggleActive(int $cardId): void
    {
        $card = Auth::user()->creditCards()->findOrFail($cardId);
        $card->update(['is_active' => ! $card->is_active]);

        session()->flash('message', 'Status do cartÃ£o atualizado com sucesso.');
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
            return 'NÃ£o definido';
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
        $suffix = $days === 0 ? ' (hoje)' : ($days === 1 ? ' (amanhÃ£)' : " (em {$days} dias)");

        return $due->format('d/m/Y') . $suffix;
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold">CartÃµes de CrÃ©dito</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Acompanhe limite, vencimento e uso da fatura.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button href="{{ route('integrations.open-finance') }}" wire:navigate variant="ghost">
                Open Finance
            </flux:button>
            <flux:button href="{{ route('credit-cards.create') }}" wire:navigate variant="primary">
                Novo CartÃ£o
            </flux:button>
        </div>
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
                        <div class="flex items-center gap-3 mb-3 flex-wrap">
                            <h2 class="text-lg font-semibold">{{ $card->name }}</h2>
                            <span class="px-2 py-1 text-xs rounded-full {{ $card->is_active ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200' }}">
                                {{ $card->is_active ? 'Ativo' : 'Inativo' }}
                            </span>
                            @if($card->open_finance_account_id)
                                <span class="px-2 py-1 text-xs rounded-full bg-emerald-100 text-emerald-800 dark:bg-emerald-900/70 dark:text-emerald-200">
                                    Open Finance
                                </span>
                            @endif
                            @if($card->brand)
                                <span class="px-2 py-1 text-xs rounded-full bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200">
                                    {{ $card->brand }}
                                </span>
                            @endif
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div>
                                <p class="text-xs text-zinc-500">Emissor</p>
                                <p class="font-medium">{{ $card->issuer ?: 'NÃ£o informado' }}</p>
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
                                <p class="font-medium">{{ $card->closing_day ?: 'NÃ£o definido' }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-zinc-500">Vencimento</p>
                                <p class="font-medium">{{ $this->nextDueDateLabel($card) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-zinc-500">TransaÃ§Ãµes vinculadas</p>
                                <p class="font-medium">{{ $card->transactions->count() }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-zinc-500">Ãšltima compra</p>
                                <p class="font-medium">
                                    {{ $lastTx ? \Carbon\Carbon::parse($lastTx)->format('d/m/Y') : 'Nunca' }}
                                </p>
                            </div>
                        </div>

                        @if($card->open_finance_account_id)
                            <div class="mt-4 rounded-xl border border-emerald-400/20 bg-emerald-500/5 px-4 py-3">
                                <div class="grid gap-3 md:grid-cols-3 text-sm">
                                    <div>
                                        <p class="font-medium text-emerald-300">Fatura sincronizada</p>
                                        <p class="text-zinc-300">R$ {{ number_format((float) ($card->open_finance_balance ?? 0), 2, ',', '.') }}</p>
                                    </div>
                                    <div>
                                        <p class="font-medium text-emerald-300">Limite disponível</p>
                                        <p class="text-zinc-300">R$ {{ number_format((float) ($card->open_finance_available_limit ?? 0), 2, ',', '.') }}</p>
                                    </div>
                                    <div class="text-zinc-400">
                                        Última sync:
                                        {{ optional($card->open_finance_synced_at)->format('d/m/Y H:i') ?: 'pendente' }}
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>

                    <div class="flex items-center gap-2">
                        <flux:button wire:click="toggleActive({{ $card->id }})" variant="ghost" size="sm" icon="{{ $card->is_active ? 'pause' : 'play' }}" />
                        <flux:button href="{{ route('credit-cards.edit', $card) }}" wire:navigate variant="ghost" size="sm" icon="pencil" />
                        <flux:button wire:click="delete({{ $card->id }})" wire:confirm="Deseja excluir este cartÃ£o?" variant="ghost" size="sm" icon="trash" class="text-red-600 hover:text-red-700 dark:text-red-400" />
                    </div>
                </div>
            </div>
        @empty
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-12 text-center">
                <p class="text-zinc-500 dark:text-zinc-400 mb-4">Nenhum cartÃ£o cadastrado.</p>
                <flux:button href="{{ route('credit-cards.create') }}" wire:navigate variant="primary">
                    Criar primeiro cartÃ£o
                </flux:button>
            </div>
        @endforelse
    </div>
</div>
