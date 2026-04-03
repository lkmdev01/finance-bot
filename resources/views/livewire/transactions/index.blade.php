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
        session()->flash('message', 'TransaÃ§Ã£o excluÃ­da com sucesso!');
    }

    public function with(): array
    {
        $query = Auth::user()->transactions()
            ->with('category');

        // Busca por descriÃ§Ã£o ou categoria
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

        // OrdenaÃ§Ã£o
        $sortField = match ($this->sortBy) {
            'amount' => 'amount',
            'description' => 'description',
            default => 'date',
        };
        $query->orderBy($sortField, $this->sortDirection);
        if ($this->sortBy !== 'date') {
            $query->orderBy('date', 'desc'); // OrdenaÃ§Ã£o secundÃ¡ria por data
        }

        // Calcular totais do perÃ­odo filtrado
        $totalIncome = (float) Auth::user()->transactions()
            ->where('type', 'income')
            ->when($this->dateFrom, fn ($q) => $q->where('date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->where('date', '<=', $this->dateTo))
            ->sum('amount');

        $totalExpenses = (float) Auth::user()->transactions()
            ->where('type', 'expense')
            ->when($this->dateFrom, fn ($q) => $q->where('date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->where('date', '<=', $this->dateTo))
            ->sum('amount');

        // Calcular saldo disponÃ­vel (mesma lÃ³gica do Dashboard)
        $totalIncomeAllTime = (float) Auth::user()->transactions()
            ->where('type', 'income')
            ->sum('amount');

        // Excluir transaÃ§Ãµes de depÃ³sito em metas
        $allExpenses = Auth::user()->transactions()
            ->where('type', 'expense')
            ->get();

        $expensesWithoutSavings = $allExpenses->filter(function ($transaction) {
            $metadata = $transaction->metadata ?? [];

            return ! isset($metadata['savings_goal_deposit_id']);
        });

        $totalExpensesAllTime = (float) $expensesWithoutSavings->sum('amount');

        $totalSavingsDeposits = (float) Auth::user()->savingsGoals()
            ->with('deposits')
            ->get()
            ->sum(fn ($goal) => $goal->deposits->sum('amount'));

        $availableBalance = $totalIncomeAllTime - $totalExpensesAllTime - $totalSavingsDeposits;

        return [
            'transactions' => $query->with('tags')->paginate(15),
            'categories' => Auth::user()->categories()->orderBy('name')->get(),
            'tags' => Auth::user()->tags()->orderBy('name')->get(),
            'totalIncome' => $totalIncome,
            'totalExpenses' => $totalExpenses,
            'availableBalance' => $availableBalance,
        ];
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">TransaÃ§Ãµes</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Gerencie suas receitas e despesas</p>
        </div>
        <flux:button href="{{ route('transactions.create') }}" wire:navigate variant="primary">
            Nova TransaÃ§Ã£o
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
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mb-2">Saldo disponÃ­vel</p>
            <p class="text-2xl font-bold {{ $availableBalance >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                R$ {{ number_format($availableBalance, 2, ',', '.') }}
            </p>
        </div>
    </div>

    <!-- Filtros -->
    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Filtros</h3>
            <flux:button wire:click="clearFilters" variant="ghost" size="sm">
                Limpar Filtros
            </flux:button>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <flux:input 
                wire:model.live.debounce.300ms="search" 
                placeholder="Buscar por descriÃ§Ã£o ou categoria..."
                icon="magnifying-glass"
            />
            
            <flux:select wire:model.live="type" placeholder="Tipo">
                <option value="">Todos</option>
                <option value="income">Receita</option>
                <option value="expense">Despesa</option>
            </flux:select>

            <flux:select wire:model.live="category" placeholder="Categoria">
                <option value="">Todas</option>
                @foreach($categories as $cat)
                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="tagFilter" placeholder="Tag">
                <option value="">Todas</option>
                @foreach($tags as $tag)
                    <option value="{{ $tag->id }}">{{ $tag->name }}</option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="sortBy" placeholder="Ordenar por">
                <option value="date">Data</option>
                <option value="amount">Valor</option>
                <option value="description">DescriÃ§Ã£o</option>
            </flux:select>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-4">
            <flux:input type="date" wire:model.live="dateFrom" label="Data Inicial" />
            <flux:input type="date" wire:model.live="dateTo" label="Data Final" />
            <flux:input 
                type="number" 
                wire:model.live.debounce.300ms="amountMin" 
                placeholder="Valor mÃ­nimo"
                step="0.01"
                min="0"
            />
            <flux:input 
                type="number" 
                wire:model.live.debounce.300ms="amountMax" 
                placeholder="Valor mÃ¡ximo"
                step="0.01"
                min="0"
            />
        </div>
    </div>

    <!-- Tabela de TransaÃ§Ãµes -->
    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                            <button wire:click="sortBy('date')" class="flex items-center gap-1 hover:text-zinc-700 dark:hover:text-zinc-200">
                                Data
                                @if($sortBy === 'date')
                                    <span class="text-zinc-400">{{ $sortDirection === 'asc' ? 'â†‘' : 'â†“' }}</span>
                                @endif
                            </button>
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                            <button wire:click="sortBy('description')" class="flex items-center gap-1 hover:text-zinc-700 dark:hover:text-zinc-200">
                                DescriÃ§Ã£o
                                @if($sortBy === 'description')
                                    <span class="text-zinc-400">{{ $sortDirection === 'asc' ? 'â†‘' : 'â†“' }}</span>
                                @endif
                            </button>
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">Categoria</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">Tipo</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                            <button wire:click="sortBy('amount')" class="flex items-center gap-1 hover:text-zinc-700 dark:hover:text-zinc-200 ml-auto">
                                Valor
                                @if($sortBy === 'amount')
                                    <span class="text-zinc-400">{{ $sortDirection === 'asc' ? 'â†‘' : 'â†“' }}</span>
                                @endif
                            </button>
                        </th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">AÃ§Ãµes</th>
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
                                        wire:confirm="Tem certeza que deseja excluir esta transaÃ§Ã£o?"
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
                                Nenhuma transaÃ§Ã£o encontrada.
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
