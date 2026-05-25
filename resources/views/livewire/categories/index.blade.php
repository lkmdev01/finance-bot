<?php

use App\Models\Category;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public string $search = '';
    public ?string $type = null;

    public function mount(): void
    {
        // Allow deep linking via query string: /categories?type=income
        $this->type = request()->query('type') ?: null;
        $this->search = (string) (request()->query('search') ?? '');
    }

    public function delete(int $categoryId): void
    {
        $category = Auth::user()->categories()->findOrFail($categoryId);
        
        if ($category->transactions()->count() > 0) {
            session()->flash('error', 'Não é possível excluir uma categoria que possui transações associadas.');
            return;
        }

        $category->delete();
        session()->flash('message', 'Categoria excluída com sucesso!');
    }

    public function with(): array
    {
        $query = Auth::user()->categories()->orderBy('name');

        if ($this->search) {
            $query->where('name', 'like', "%{$this->search}%");
        }

        if ($this->type) {
            $query->where('type', $this->type);
        }

        return [
            'categories' => $query->get(),
        ];
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Categorias</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Organize suas receitas e despesas por categoria</p>
        </div>
        <flux:button href="{{ route('categories.create') }}" wire:navigate variant="primary">
            Nova Categoria
        </flux:button>
    </div>

    <!-- Filtros -->
    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <flux:input 
                wire:model.live.debounce.300ms="search" 
                placeholder="Buscar por nome..."
                icon="magnifying-glass"
            />
            
            <flux:select wire:model.live="type" placeholder="Tipo">
                <option value="">Todos</option>
                <option value="income">Receita</option>
                <option value="expense">Despesa</option>
            </flux:select>
        </div>
    </div>

    <!-- Grid de Categorias -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        @forelse($categories as $category)
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 hover:shadow-lg transition-shadow">
                <div class="flex items-start justify-between">
                    <div class="flex items-center gap-3 flex-1">
                        @if($category->icon)
                            <div class="text-2xl">{{ $category->icon }}</div>
                        @endif
                        <div class="flex-1 min-w-0">
                            <h3 class="font-semibold text-lg truncate">{{ $category->name }}</h3>
                            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">
                                <span class="px-2 py-1 text-xs font-medium rounded-full {{ $category->type === 'income' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' }}">
                                    {{ $category->type === 'income' ? 'Receita' : 'Despesa' }}
                                </span>
                            </p>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-2">
                                {{ $category->transactions()->count() }} transação(ões)
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <flux:button 
                            href="{{ route('categories.edit', $category) }}" 
                            wire:navigate
                            variant="ghost"
                            size="sm"
                            icon="pencil"
                        />
                        <flux:button 
                            wire:click="delete({{ $category->id }})"
                            wire:confirm="Tem certeza que deseja excluir esta categoria?"
                            variant="ghost"
                            size="sm"
                            icon="trash"
                            class="text-red-600 hover:text-red-700 dark:text-red-400"
                        />
                    </div>
                </div>
                @if($category->color)
                    <div class="mt-4 h-2 rounded-full" style="background-color: {{ $category->color }}"></div>
                @endif
            </div>
        @empty
            <div class="col-span-full bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-12 text-center">
                <p class="text-zinc-500 dark:text-zinc-400">Nenhuma categoria encontrada.</p>
            </div>
        @endforelse
    </div>
</div>
