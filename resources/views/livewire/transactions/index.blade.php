<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public ?string $type = null;

    public ?string $category = null;

    public string $dateFrom = '';

    public string $dateTo = '';

    public ?string $amountMin = null;

    public ?string $amountMax = null;

    public ?int $tagFilter = null;

    public string $sortBy = 'date';

    public string $sortDirection = 'desc';

    public function mount(): void
    {
        $this->dateFrom = now()->startOfMonth()->format('Y-m-d');
        $this->dateTo = now()->endOfMonth()->format('Y-m-d');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingType(): void
    {
        $this->resetPage();
    }

    public function updatingCategory(): void
    {
        $this->resetPage();
    }

    public function updatingDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatingDateTo(): void
    {
        $this->resetPage();
    }

    public function updatingAmountMin(): void
    {
        $this->resetPage();
    }

    public function updatingAmountMax(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if ($this->sortBy === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $field;
            $this->sortDirection = 'desc';
        }
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->type = null;
        $this->category = null;
        $this->dateFrom = now()->startOfMonth()->format('Y-m-d');
        $this->dateTo = now()->endOfMonth()->format('Y-m-d');
        $this->amountMin = null;
        $this->amountMax = null;
        $this->sortBy = 'date';
        $this->sortDirection = 'desc';
        $this->resetPage();
    }

    public function delete(int $transactionId): void
    {
        $transaction = Auth::user()->transactions()->findOrFail($transactionId);
        $transaction->delete();
        session()->flash('message', 'Transação excluída com sucesso!');
    }

    public function with(): array
    {
        $query = Auth::user()->transactions()
            ->with('category');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('description', 'like', "%{$this->search}%")
                    ->orWhereHas('category', function ($q) {
                        $q->where('name', 'like', "%{$this->search}%");
                    });
            });
        }

        if ($this->type) {
            $query->where('type', $this->type);
        }

        if ($this->category) {
            $query->where('category_id', $this->category);
        }

        if ($this->dateFrom) {
            $query->where('date', '>=', $this->dateFrom);
        }

        if ($this->dateTo) {
            $query->where('date', '<=', $this->dateTo);
        }

        if ($this->amountMin) {
            $query->where('amount', '>=', $this->amountMin);
        }

        if ($this->amountMax) {
            $query->where('amount', '<=', $this->amountMax);
        }

        if ($this->tagFilter) {
            $query->whereHas('tags', function ($q) {
                $q->where('tags.id', $this->tagFilter);
            });
        }

        $sortField = match ($this->sortBy) {
            'amount' => 'amount',
            'description' => 'description',
            default => 'date',
        };
        $query->orderBy($sortField, $this->sortDirection);
        if ($this->sortBy !== 'date') {
            $query->orderBy('date', 'desc');
        }

        $user = Auth::user();

        $totalIncome = (float) $user->transactions()
            ->where('type', 'income')
            ->when($this->dateFrom, fn ($q) => $q->where('date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->where('date', '<=', $this->dateTo))
            ->sum('amount');

        $totalExpenses = (float) $user->transactions()
            ->where('type', 'expense')
            ->when($this->dateFrom, fn ($q) => $q->where('date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->where('date', '<=', $this->dateTo))
            ->sum('amount');

        // Saldo disponível via SQL — sem carregar todas as linhas em memória
        $totalIncomeAllTime = (float) $user->transactions()
            ->where('type', 'income')
            ->sum('amount');

        // Exclui transações de depósito em metas via JSON SQL (sem carregar linhas em PHP)
        $totalExpensesAllTime = (float) $user->transactions()
            ->where('type', 'expense')
            ->where(function ($q) {
                $q->whereNull('metadata')
                    ->orWhereRaw("JSON_EXTRACT(metadata, '$.savings_goal_deposit_id') IS NULL");
            })
            ->sum('amount');

        $totalSavingsDeposits = (float) \App\Models\SavingsGoalDeposit::whereHas(
            'savingsGoal',
            fn ($q) => $q->where('user_id', $user->id)
        )->sum('amount');


        $availableBalance = $totalIncomeAllTime - $totalExpensesAllTime - $totalSavingsDeposits;

        return [
            'transactions' => $query->with('tags')->paginate(15),
            'categories' => $user->categories()->orderBy('name')->get(),
            'tags' => $user->tags()->orderBy('name')->get(),
            'totalIncome' => $totalIncome,
            'totalExpenses' => $totalExpenses,
            'availableBalance' => $availableBalance,
        ];
    }
}; ?>


<div class="space-y-6 p-4 sm:p-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold">Transações</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Gerencie suas receitas e despesas</p>
        </div>
        <flux:button href="{{ route('transactions.create') }}" wire:navigate variant="primary" class="w-full sm:w-auto">
            Nova Transação
        </flux:button>
    </div>

    <!-- Resumo -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mb-2">Total de Receitas</p>
            <p class="text-2xl font-bold text-green-600 dark:text-green-400">
                R$ {{ number_format($totalIncome, 2, ',', '.') }}
            </p>
        </div>
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mb-2">Total de Despesas</p>
            <p class="text-2xl font-bold text-red-600 dark:text-red-400">
                R$ {{ number_format($totalExpenses, 2, ',', '.') }}
            </p>
        </div>
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mb-2">Saldo disponível</p>
            <p class="text-2xl font-bold {{ $availableBalance >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                R$ {{ number_format($availableBalance, 2, ',', '.') }}
            </p>
        </div>
    </div>

    <!-- Filtros -->
    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <h3 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Filtros</h3>
            <flux:button wire:click="clearFilters" variant="ghost" size="sm" class="w-full sm:w-auto">
                Limpar Filtros
            </flux:button>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <flux:input 
                wire:model.live.debounce.300ms="search" 
                placeholder="Buscar por descrição ou categoria..."
                icon="magnifying-glass"
            />
            
            <flux:select wire:model.live.debounce.150ms="type" placeholder="Tipo">
                <option value="">Todos</option>
                <option value="income">Receita</option>
                <option value="expense">Despesa</option>
            </flux:select>

            <flux:select wire:model.live.debounce.150ms="category" placeholder="Categoria">
                <option value="">Todas</option>
                @foreach($categories as $cat)
                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live.debounce.150ms="tagFilter" placeholder="Tag">
                <option value="">Todas</option>
                @foreach($tags as $tag)
                    <option value="{{ $tag->id }}">{{ $tag->name }}</option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live.debounce.150ms="sortBy" placeholder="Ordenar por">
                <option value="date">Data</option>
                <option value="amount">Valor</option>
                <option value="description">Descrição</option>
            </flux:select>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-4">
            <flux:input type="date" wire:model.live.debounce.300ms="dateFrom" label="Data Inicial" />
            <flux:input type="date" wire:model.live.debounce.300ms="dateTo" label="Data Final" />
            <flux:input 
                type="number" 
                wire:model.live.debounce.300ms="amountMin" 
                placeholder="Valor mínimo"
                step="0.01"
                min="0"
            />
            <flux:input 
                type="number" 
                wire:model.live.debounce.300ms="amountMax" 
                placeholder="Valor máximo"
                step="0.01"
                min="0"
            />
        </div>
    </div>

    <!-- Tabela de Transações -->
    <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 md:hidden">
        <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @forelse($transactions as $transaction)
                <article class="p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="break-words text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                {{ $transaction->description ?? '-' }}
                            </p>
                            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                                <span>{{ $transaction->date->format('d/m/Y') }}</span>
                                @if($transaction->category)
                                    <span>Â·</span>
                                    <span>
                                        @if($transaction->category->icon)
                                            {{ $transaction->category->icon }}
                                        @endif
                                        {{ $transaction->category->name }}
                                    </span>
                                @endif
                            </div>
                        </div>
                        <p class="shrink-0 text-right text-sm font-black {{ $transaction->type === 'income' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                            {{ $transaction->type === 'income' ? '+' : '-' }}R$ {{ number_format($transaction->amount, 2, ',', '.') }}
                        </p>
                    </div>

                    <div class="mt-3 flex items-center justify-between gap-3">
                        <span class="rounded-full px-2 py-1 text-xs font-medium {{ $transaction->type === 'income' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' }}">
                            {{ $transaction->type === 'income' ? 'Receita' : 'Despesa' }}
                        </span>
                        <div class="flex items-center gap-2">
                            <flux:button
                                href="{{ route('transactions.edit', $transaction) }}"
                                wire:navigate
                                variant="ghost"
                                size="sm"
                                icon="pencil"
                            />
                            <flux:button
                                wire:click="delete({{ $transaction->id }})"
                                wire:confirm="Tem certeza que deseja excluir esta transaÃ§Ã£o?"
                                variant="ghost"
                                size="sm"
                                icon="trash"
                                class="text-red-600 hover:text-red-700 dark:text-red-400"
                            />
                        </div>
                    </div>
                </article>
            @empty
                <div class="px-6 py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">
                    Nenhuma transaÃ§Ã£o encontrada.
                </div>
            @endforelse
        </div>

        @if($transactions->hasPages())
            <div class="border-t border-zinc-200 px-4 py-4 dark:border-zinc-700">
                {{ $transactions->links() }}
            </div>
        @endif
    </div>

    <div class="hidden overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 md:block">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                            <button wire:click="sortBy('date')" class="flex items-center gap-1 hover:text-zinc-700 dark:hover:text-zinc-200">
                                Data
                                @if($sortBy === 'date')
                                    <span class="text-zinc-400">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                @endif
                            </button>
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                            <button wire:click="sortBy('description')" class="flex items-center gap-1 hover:text-zinc-700 dark:hover:text-zinc-200">
                                Descrição
                                @if($sortBy === 'description')
                                    <span class="text-zinc-400">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                @endif
                            </button>
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">Categoria</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">Tipo</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                            <button wire:click="sortBy('amount')" class="flex items-center gap-1 hover:text-zinc-700 dark:hover:text-zinc-200 ml-auto">
                                Valor
                                @if($sortBy === 'amount')
                                    <span class="text-zinc-400">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                @endif
                            </button>
                        </th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse($transactions as $transaction)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800">
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                {{ $transaction->date->format('d/m/Y') }}
                            </td>
                            <td class="px-6 py-4 text-sm">
                                {{ $transaction->description ?? '-' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                @if($transaction->category)
                                    <span class="inline-flex items-center gap-2">
                                        @if($transaction->category->icon)
                                            <span>{{ $transaction->category->icon }}</span>
                                        @endif
                                        {{ $transaction->category->name }}
                                    </span>
                                @else
                                    <span class="text-zinc-400">-</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 py-1 text-xs font-medium rounded-full {{ $transaction->type === 'income' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' }}">
                                    {{ $transaction->type === 'income' ? 'Receita' : 'Despesa' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium {{ $transaction->type === 'income' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                {{ $transaction->type === 'income' ? '+' : '-' }}R$ {{ number_format($transaction->amount, 2, ',', '.') }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center text-sm">
                                <div class="flex items-center justify-center gap-2">
                                    <flux:button 
                                        href="{{ route('transactions.edit', $transaction) }}" 
                                        wire:navigate
                                        variant="ghost"
                                        size="sm"
                                        icon="pencil"
                                    />
                                    <flux:button 
                                        wire:click="delete({{ $transaction->id }})"
                                        wire:confirm="Tem certeza que deseja excluir esta transação?"
                                        variant="ghost"
                                        size="sm"
                                        icon="trash"
                                        class="text-red-600 hover:text-red-700 dark:text-red-400"
                                    />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">
                                Nenhuma transação encontrada.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($transactions->hasPages())
            <div class="px-6 py-4 border-t border-zinc-200 dark:border-zinc-700">
                {{ $transactions->links() }}
            </div>
        @endif
    </div>
</div>
