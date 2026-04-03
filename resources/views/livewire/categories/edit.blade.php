<?php

use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use Livewire\Volt\Component;

new class extends Component {
    public ?Category $category = null;
    
    public string $name;
    public string $type;
    public ?string $color = null;
    public ?string $icon = null;

    public function mount(?Category $category = null): void
    {
        if (!$category) {
            $category = Category::findOrFail(request()->route('category'));
        }
        $this->category = $category;
        $this->name = $category->name;
        $this->type = $category->type;
        $this->color = $category->color;
        $this->icon = $category->icon;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:income,expense'],
            'color' => ['nullable', 'string', 'max:7'],
            'icon' => ['nullable', 'string', 'max:50'],
        ], [
            'name.required' => 'O nome da categoria Ã© obrigatÃ³rio.',
            'name.max' => 'O nome nÃ£o pode ter mais de 255 caracteres.',
            'type.required' => 'O tipo da categoria Ã© obrigatÃ³rio.',
            'type.in' => 'O tipo deve ser receita ou despesa.',
            'color.max' => 'A cor deve ter no mÃ¡ximo 7 caracteres.',
            'icon.max' => 'O Ã­cone deve ter no mÃ¡ximo 50 caracteres.',
        ]);

        $this->category->update($validated);

        session()->flash('message', 'Categoria atualizada com sucesso!');
        
        $this->redirect(route('categories.index'), navigate: true);
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Editar Categoria</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Atualize os dados da categoria</p>
        </div>
        <flux:button href="{{ route('categories.index') }}" wire:navigate variant="ghost">
            Voltar
        </flux:button>
    </div>

    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
        <form wire:submit="save" class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <flux:field>
                    <flux:label>Nome</flux:label>
                    <flux:input wire:model="name" placeholder="Ex: AlimentaÃ§Ã£o" required />
                    <flux:error name="name" />
                </flux:field>

                <flux:field>
                    <flux:label>Tipo</flux:label>
                    <flux:radio.group wire:model="type">
                        <flux:radio value="income" label="Receita" />
                        <flux:radio value="expense" label="Despesa" />
                    </flux:radio.group>
                    <flux:error name="type" />
                </flux:field>

                <flux:field>
                    <flux:label>Cor (opcional)</flux:label>
                    <flux:input type="color" wire:model="color" />
                    <flux:description>Escolha uma cor para identificar a categoria</flux:description>
                    <flux:error name="color" />
                </flux:field>

                <flux:field>
                    <flux:label>Ãcone (opcional)</flux:label>
                    <flux:input wire:model="icon" placeholder="Ex: ðŸ”, ðŸš—, ðŸ’°" maxlength="2" />
                    <flux:description>Use um emoji ou sÃ­mbolo para representar a categoria</flux:description>
                    <flux:error name="icon" />
                </flux:field>
            </div>

            <div class="flex items-center justify-end gap-3">
                <flux:button href="{{ route('categories.index') }}" wire:navigate variant="ghost">
                    Cancelar
                </flux:button>
                <flux:button type="submit" variant="primary">
                    Atualizar Categoria
                </flux:button>
            </div>
        </form>
    </div>
</div>
