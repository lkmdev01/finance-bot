<?php

use Livewire\Volt\Component;

new class extends Component {
    public string $title = '';
    public string $body = '';

    public function save(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'body' => ['required', 'string', 'min:3'],
        ]);

        auth()->user()->notes()->create([
            ...$validated,
            'source' => 'dashboard',
        ]);

        session()->flash('message', 'Nota criada com sucesso.');
        $this->redirect(route('notes.index'), navigate: true);
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Nova nota</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Use para guardar um insight rapido e recuperar depois.</p>
        </div>
        <flux:button href="{{ route('notes.index') }}" wire:navigate variant="ghost">Voltar</flux:button>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <form wire:submit="save" class="space-y-6">
            <flux:input wire:model="title" label="Titulo" placeholder="Ex: Ideia para o projeto X" />
            <flux:textarea wire:model="body" label="Conteudo" rows="8" placeholder="Escreva sua nota aqui..." />

            <div class="flex justify-end gap-3">
                <flux:button href="{{ route('notes.index') }}" wire:navigate variant="ghost">Cancelar</flux:button>
                <flux:button type="submit" variant="primary">Salvar nota</flux:button>
            </div>
        </form>
    </div>
</div>

