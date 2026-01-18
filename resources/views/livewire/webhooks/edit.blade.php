<?php

use App\Models\Webhook;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public ?Webhook $webhook = null;
    public string $name = '';
    public string $url = '';
    public ?string $secret = null;
    public array $selectedEvents = [];

    protected array $availableEvents = [
        'transaction.created' => 'Transação Criada',
        'transaction.updated' => 'Transação Atualizada',
        'transaction.deleted' => 'Transação Excluída',
        'budget.exceeded' => 'Orçamento Excedido',
        'savings_goal.milestone' => 'Marco de Meta Atingido',
        'savings_goal.deadline' => 'Prazo de Meta Próximo',
        'savings_goal.low_progress' => 'Progresso Baixo na Meta',
        'expense_plan.exceeded' => 'Plano de Despesas Excedido',
    ];

    public function mount(?Webhook $webhook = null): void
    {
        if (! $webhook) {
            $webhook = Auth::user()->webhooks()->findOrFail(request()->route('webhook'));
        }
        $this->webhook = $webhook;
        $this->name = $webhook->name ?? '';
        $this->url = $webhook->url ?? '';
        $this->secret = $webhook->secret;
        $this->selectedEvents = $webhook->events ?? [];
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url'],
            'secret' => ['nullable', 'string', 'max:255'],
            'selectedEvents' => ['required', 'array', 'min:1'],
        ], [
            'name.required' => 'O nome do webhook é obrigatório.',
            'url.required' => 'A URL é obrigatória.',
            'url.url' => 'A URL deve ser válida.',
            'selectedEvents.required' => 'Selecione pelo menos um evento.',
            'selectedEvents.min' => 'Selecione pelo menos um evento.',
        ]);

        $this->webhook->update([
            'name' => $this->name,
            'url' => $this->url,
            'secret' => $this->secret,
            'events' => $this->selectedEvents,
        ]);

        session()->flash('message', 'Webhook atualizado com sucesso!');
        $this->redirect(route('webhooks.index'), navigate: true);
    }

    public function with(): array
    {
        return [
            'availableEvents' => $this->availableEvents,
        ];
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Editar Webhook</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Atualize as informações do webhook</p>
        </div>
        <flux:button href="{{ route('webhooks.index') }}" wire:navigate variant="ghost">
            Voltar
        </flux:button>
    </div>

    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
        <form wire:submit="save" class="space-y-6">
            <flux:field>
                <flux:label>Nome</flux:label>
                <flux:input wire:model="name" placeholder="Ex: Meu Webhook de Produção" required />
                <flux:error name="name" />
            </flux:field>

            <flux:field>
                <flux:label>URL</flux:label>
                <flux:input wire:model="url" type="url" placeholder="https://exemplo.com/webhook" required />
                <flux:description>
                    URL que receberá as notificações de eventos.
                </flux:description>
                <flux:error name="url" />
            </flux:field>

            <flux:field>
                <flux:label>Secret (Opcional)</flux:label>
                <flux:input wire:model="secret" type="password" placeholder="Chave secreta para validação" />
                <flux:description>
                    Chave secreta usada para assinar as requisições. Se configurada, será incluída no campo "signature" do payload.
                </flux:description>
                <flux:error name="secret" />
            </flux:field>

            <flux:field>
                <flux:label>Eventos</flux:label>
                <flux:description>
                    Selecione os eventos que devem disparar este webhook.
                </flux:description>
                <div class="space-y-2 max-h-64 overflow-y-auto border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                    @foreach($availableEvents as $event => $label)
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input 
                                type="checkbox" 
                                wire:model="selectedEvents"
                                value="{{ $event }}"
                                class="rounded border-zinc-300 text-blue-600 focus:ring-blue-500"
                            />
                            <div class="flex-1">
                                <span class="text-sm font-medium">{{ $label }}</span>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400 block">{{ $event }}</span>
                            </div>
                        </label>
                    @endforeach
                </div>
                <flux:error name="selectedEvents" />
            </flux:field>

            <div class="flex items-center justify-end gap-3">
                <flux:button href="{{ route('webhooks.index') }}" wire:navigate variant="ghost">
                    Cancelar
                </flux:button>
                <flux:button type="submit" variant="primary">
                    Salvar Alterações
                </flux:button>
            </div>
        </form>
    </div>
</div>
