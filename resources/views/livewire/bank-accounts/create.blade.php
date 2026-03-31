<?php

use Livewire\Volt\Component;

new class extends Component {
    public string $name = '';
    public ?string $institution = null;
    public string $type = 'checking';
    public ?string $opening_balance = null;
    public string $currency = 'BRL';
    public ?string $color = null;
    public bool $is_active = true;

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'institution' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:checking,savings,wallet,investment'],
            'opening_balance' => ['nullable', 'numeric'],
            'currency' => ['required', 'string', 'size:3'],
            'color' => ['nullable', 'string', 'max:20'],
            'is_active' => ['boolean'],
        ]);

        auth()->user()->bankAccounts()->create([
            ...$validated,
            'opening_balance' => $this->opening_balance ?: 0,
        ]);

        session()->flash('message', 'Conta bancária criada com sucesso.');
        $this->redirect(route('bank-accounts.index'), navigate: true);
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Nova Conta Bancária</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Cadastre uma conta para organizar saldos e transações.</p>
        </div>
        <flux:button href="{{ route('bank-accounts.index') }}" wire:navigate variant="ghost">Voltar</flux:button>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <form wire:submit="save" class="space-y-6">
            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <flux:input wire:model="name" label="Nome" placeholder="Ex: Nubank principal" />
                <flux:input wire:model="institution" label="Instituição" placeholder="Ex: Nubank" />

                <flux:select wire:model="type" label="Tipo">
                    <option value="checking">Conta corrente</option>
                    <option value="savings">Poupança</option>
                    <option value="wallet">Carteira</option>
                    <option value="investment">Investimentos</option>
                </flux:select>

                <flux:input wire:model="opening_balance" type="number" step="0.01" label="Saldo inicial" placeholder="0.00" />
                <flux:input wire:model="currency" label="Moeda" />
                <flux:input wire:model="color" label="Cor (opcional)" placeholder="#1D4ED8" />
            </div>

            <flux:checkbox wire:model="is_active" label="Conta ativa" />

            <div class="flex justify-end gap-3">
                <flux:button href="{{ route('bank-accounts.index') }}" wire:navigate variant="ghost">Cancelar</flux:button>
                <flux:button type="submit" variant="primary">Salvar Conta</flux:button>
            </div>
        </form>
    </div>
</div>
