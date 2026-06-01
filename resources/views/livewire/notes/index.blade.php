<?php

use App\Models\Note;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public string $q = '';

    public function delete(int $noteId): void
    {
        $note = Auth::user()->notes()->findOrFail($noteId);
        $note->delete();

        session()->flash('message', 'Nota excluida com sucesso.');
    }

    public function with(): array
    {
        $query = Auth::user()->notes()->orderByDesc('id');

        $q = trim($this->q);
        if ($q !== '') {
            $query->where(function ($builder) use ($q) {
                $builder
                    ->where('title', 'like', '%'.$q.'%')
                    ->orWhere('body', 'like', '%'.$q.'%');
            });
        }

        return [
            'notes' => $query->limit(50)->get(),
        ];
    }

    public function excerpt(Note $note): string
    {
        $body = trim((string) $note->body);
        $body = preg_replace('/\\s+/u', ' ', $body) ?? $body;

        return mb_strlen($body) > 160 ? mb_substr($body, 0, 160).'...' : $body;
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold">Notas</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Salve insights e encontre depois pelo painel ou via WhatsApp.</p>
        </div>
        <div class="flex items-center gap-3">
            <flux:input wire:model.live="q" placeholder="Buscar nas notas..." class="min-w-[220px]" />
            <flux:button href="{{ route('notes.create') }}" wire:navigate variant="primary">Nova nota</flux:button>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4">
        @forelse($notes as $note)
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-semibold truncate">{{ $note->title }}</h2>
                            @if(! blank($note->source))
                                <span class="px-2 py-1 text-xs rounded-full bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                                    {{ $note->source }}
                                </span>
                            @endif
                        </div>
                        <p class="mt-2 text-sm text-zinc-700 dark:text-zinc-300">{{ $this->excerpt($note) }}</p>
                        <p class="mt-3 text-xs text-zinc-500">Criada em {{ $note->created_at?->format('d/m/Y H:i') }}</p>
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
                        <flux:button href="{{ route('notes.edit', $note) }}" wire:navigate variant="ghost">Editar</flux:button>
                        <flux:button
                            wire:click="delete({{ $note->id }})"
                            wire:confirm="Tem certeza que deseja excluir esta nota?"
                            variant="danger"
                        >
                            Excluir
                        </flux:button>
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-zinc-300 dark:border-zinc-700 p-10 text-center">
                <p class="text-sm text-zinc-600 dark:text-zinc-400">Nenhuma nota encontrada.</p>
                <p class="mt-2 text-xs text-zinc-500">Dica: no WhatsApp, envie: "anota: ideia para o projeto X".</p>
            </div>
        @endforelse
    </div>
</div>

