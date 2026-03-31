<?php

use Livewire\Volt\Component;

new class extends Component {
    public string $name = '';
    public ?string $issuer = null;
    public ?string $brand = null;
    public ?string $credit_limit = null;
    public ?string $opening_balance = null;
    public ?int $closing_day = null;
    public ?int $due_day = null;
    public bool $is_active = true;

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'issuer' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
            'closing_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'due_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'is_active' => ['boolean'],
        ]);

        auth()->user()->creditCards()->create([
            ...$validated,
            'credit_limit' => $this->credit_limit ?: 0,
            'opening_balance' => $this->opening_balance ?: 0,
        ]);

        session()->flash('message', 'Cartão criado com sucesso.');
        $this->redirect(route('credit-cards.index'), navigate: true);
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Novo Cartão de Crédito</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Cadastre o cartão para controlar limite e gastos.</p>
        </div>
        <flux:button href="{{ route('credit-cards.index') }}" wire:navigate variant="ghost">Voltar</flux:button>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <form wire:submit="save" class="space-y-6">
            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <flux:input wire:model="name" label="Nome" placeholder="Ex: Cartão principal" />
                <flux:input wire:model="issuer" label="Emissor" placeholder="Ex: Nubank" />
                <flux:input wire:model="brand" label="Bandeira" placeholder="Ex: Visa" />
                <flux:input wire:model="credit_limit" type="number" step="0.01" label="Limite" placeholder="0.00" />
                <flux:input wire:model="opening_balance" type="number" step="0.01" label="Saldo inicial da fatura" placeholder="0.00" />
                <flux:input wire:model="closing_day" type="number" min="1" max="31" label="Dia de fechamento" />
                <flux:input wire:model="due_day" type="number" min="1" max="31" label="Dia de vencimento" />
            </div>

            <flux:checkbox wire:model="is_active" label="Cartão ativo" />

            <div class="flex justify-end gap-3">
                <flux:button href="{{ route('credit-cards.index') }}" wire:navigate variant="ghost">Cancelar</flux:button>
                <flux:button type="submit" variant="primary">Salvar Cartão</flux:button>
            </div>
        </form>
    </div>
</div>
