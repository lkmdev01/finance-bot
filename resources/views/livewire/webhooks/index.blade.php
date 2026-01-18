<?php

use App\Models\Webhook;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public function delete(int $webhookId): void
    {
        $webhook = Auth::user()->webhooks()->findOrFail($webhookId);
        $webhook->delete();
        session()->flash('message', 'Webhook excluído com sucesso!');
    }

    public function toggleActive(int $webhookId): void
    {
        $webhook = Auth::user()->webhooks()->findOrFail($webhookId);
        $webhook->update(['is_active' => ! $webhook->is_active]);
        session()->flash('message', 'Status do webhook atualizado!');
    }

    public function with(): array
    {
        return [
            'webhooks' => Auth::user()->webhooks()
                ->orderBy('created_at', 'desc')
                ->paginate(15),
        ];
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Webhooks</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Configure webhooks para receber notificações de eventos</p>
        </div>
        <flux:button href="{{ route('webhooks.create') }}" wire:navigate variant="primary">
            Novo Webhook
        </flux:button>
    </div>

    @if($webhooks->count() > 0)
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Nome</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">URL</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Eventos</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Status</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Estatísticas</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach($webhooks as $webhook)
                            <tr>
                                <td class="px-6 py-4">
                                    <div class="text-sm font-medium">{{ $webhook->name }}</div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm text-zinc-500 dark:text-zinc-400 max-w-xs truncate">
                                        {{ $webhook->url }}
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($webhook->events as $event)
                                            <span class="px-2 py-1 text-xs font-medium rounded bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200">
                                                {{ $event }}
                                            </span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    @if($webhook->is_active)
                                        <span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                            Ativo
                                        </span>
                                    @else
                                        <span class="px-2 py-1 text-xs font-medium rounded-full bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200">
                                            Inativo
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                        <div>✓ {{ $webhook->success_count }}</div>
                                        <div>✗ {{ $webhook->failure_count }}</div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <flux:button 
                                            wire:click="toggleActive({{ $webhook->id }})"
                                            variant="ghost"
                                            size="sm"
                                        >
                                            {{ $webhook->is_active ? 'Desativar' : 'Ativar' }}
                                        </flux:button>
                                        <flux:button 
                                            href="{{ route('webhooks.edit', $webhook) }}"
                                            wire:navigate
                                            variant="ghost"
                                            size="sm"
                                        >
                                            Editar
                                        </flux:button>
                                        <flux:button 
                                            wire:click="delete({{ $webhook->id }})"
                                            wire:confirm="Tem certeza que deseja excluir este webhook?"
                                            variant="ghost"
                                            size="sm"
                                        >
                                            Excluir
                                        </flux:button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-4">
            {{ $webhooks->links() }}
        </div>
    @else
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-12 text-center">
            <p class="text-zinc-500 dark:text-zinc-400 mb-4">Nenhum webhook configurado ainda.</p>
            <flux:button href="{{ route('webhooks.create') }}" wire:navigate variant="primary">
                Criar Primeiro Webhook
            </flux:button>
        </div>
    @endif
</div>
