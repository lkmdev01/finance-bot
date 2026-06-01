<?php

use App\Models\Note;
use Livewire\Volt\Component;

new class extends Component {
    public Note $note;
    public string $title = '';
    public string $body = '';

    public function mount(Note $note): void
    {
        abort_unless($note->user_id === auth()->id(), 403);

        $this->note = $note;
        $this->title = (string) $note->title;
        $this->body = (string) $note->body;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'body' => ['required', 'string', 'min:3'],
        ]);

        $this->note->update($validated);

        session()->flash('message', 'Nota atualizada com sucesso.');
        $this->redirect(route('notes.index'), navigate: true);
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Editar nota</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Ajuste o titulo ou o conteudo.</p>
        </div>
        <flux:button href="{{ route('notes.index') }}" wire:navigate variant="ghost">Voltar</flux:button>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <form wire:submit="save" class="space-y-6">
            <flux:input wire:model="title" label="Titulo" />
            <flux:textarea wire:model="body" label="Conteudo" rows="10" />

            <div class="flex justify-end gap-3">
                <flux:button href="{{ route('notes.index') }}" wire:navigate variant="ghost">Cancelar</flux:button>
                <flux:button type="submit" variant="primary">Salvar alteracoes</flux:button>
            </div>
        </form>
    </div>
</div>

