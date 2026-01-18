<?php

use App\Models\Tag;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $name = '';
    public string $color = '#6366f1';
    public ?Tag $editingTag = null;

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', 'unique:tags,name,NULL,id,user_id,'.Auth::id()],
            'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [
            'name.required' => 'O nome da tag é obrigatório.',
            'name.unique' => 'Você já possui uma tag com este nome.',
            'color.required' => 'A cor é obrigatória.',
            'color.regex' => 'A cor deve ser um código hexadecimal válido.',
        ]);

        Auth::user()->tags()->create($validated);

        $this->reset(['name', 'color']);
        session()->flash('message', 'Tag criada com sucesso!');
    }

    public function edit(Tag $tag): void
    {
        $this->editingTag = $tag;
        $this->name = $tag->name;
        $this->color = $tag->color;
    }

    public function update(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', 'unique:tags,name,'.$this->editingTag->id.',id,user_id,'.Auth::id()],
            'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [
            'name.required' => 'O nome da tag é obrigatório.',
            'name.unique' => 'Você já possui uma tag com este nome.',
            'color.required' => 'A cor é obrigatória.',
            'color.regex' => 'A cor deve ser um código hexadecimal válido.',
        ]);

        $this->editingTag->update($validated);

        $this->reset(['name', 'color', 'editingTag']);
        session()->flash('message', 'Tag atualizada com sucesso!');
    }

    public function cancelEdit(): void
    {
        $this->reset(['name', 'color', 'editingTag']);
    }

    public function delete(int $tagId): void
    {
        $tag = Auth::user()->tags()->findOrFail($tagId);
        $tag->delete();
        session()->flash('message', 'Tag excluída com sucesso!');
    }

    public function with(): array
    {
        return [
            'tags' => Auth::user()->tags()
                ->withCount('transactions')
                ->orderBy('name')
                ->paginate(15),
        ];
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Tags</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Organize suas transações com tags personalizadas</p>
        </div>
    </div>

    <!-- Formulário de Criação/Edição -->
    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
        <h2 class="text-lg font-semibold mb-4">
            {{ $editingTag ? 'Editar Tag' : 'Nova Tag' }}
        </h2>
        <form wire:submit="{{ $editingTag ? 'update' : 'save' }}" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Nome</flux:label>
                    <flux:input wire:model="name" placeholder="Ex: Urgente, Pessoal, Trabalho" required />
                    <flux:error name="name" />
                </flux:field>

                <flux:field>
                    <flux:label>Cor</flux:label>
                    <div class="flex items-center gap-3">
                        <flux:input type="color" wire:model="color" class="w-20 h-10" required />
                        <flux:input type="text" wire:model="color" placeholder="#6366f1" required />
                    </div>
                    <flux:error name="color" />
                </flux:field>
            </div>

            <div class="flex items-center gap-3">
                <flux:button type="submit" variant="primary">
                    {{ $editingTag ? 'Atualizar' : 'Criar' }} Tag
                </flux:button>
                @if($editingTag)
                    <flux:button type="button" wire:click="cancelEdit" variant="ghost">
                        Cancelar
                    </flux:button>
                @endif
            </div>
        </form>
    </div>

    <!-- Lista de Tags -->
    @if($tags->count() > 0)
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
            <h2 class="text-lg font-semibold mb-4">Suas Tags</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($tags as $tag)
                    <div class="flex items-center justify-between p-4 border border-zinc-200 dark:border-zinc-700 rounded-lg">
                        <div class="flex items-center gap-3">
                            <span 
                                class="px-3 py-1 text-sm font-medium rounded-full"
                                style="background-color: {{ $tag->color }}20; color: {{ $tag->color }}"
                            >
                                {{ $tag->name }}
                            </span>
                            <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $tag->transactions_count }} transação(ões)
                            </span>
                        </div>
                        <div class="flex items-center gap-2">
                            <flux:button 
                                wire:click="edit({{ $tag->id }})"
                                variant="ghost"
                                size="sm"
                                icon="pencil"
                            />
                            <flux:button 
                                wire:click="delete({{ $tag->id }})"
                                wire:confirm="Tem certeza que deseja excluir esta tag?"
                                variant="ghost"
                                size="sm"
                                icon="trash"
                                class="text-red-600 hover:text-red-700 dark:text-red-400"
                            />
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-4">
                {{ $tags->links() }}
            </div>
        </div>
    @else
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-12 text-center">
            <p class="text-zinc-500 dark:text-zinc-400">Nenhuma tag criada ainda. Crie sua primeira tag acima.</p>
        </div>
    @endif
</div>
