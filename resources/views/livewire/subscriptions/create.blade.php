<?php

use Livewire\Volt\Component;

new class extends Component {
    public string $name = '';
    public ?string $description = null;
    public ?string $amount = null;
    public string $billing_cycle = 'monthly';
    public ?int $due_day = null;
    public string $start_date;
    public ?int $category_id = null;
    public ?int $bank_account_id = null;
    public ?int $credit_card_id = null;
    public bool $auto_record = false;
    public bool $is_active = true;

    public function mount(): void
    {
        $this->start_date = now()->format('Y-m-d');
        $this->due_day = now()->day;
    }

    public function save(): void
    {
        $this->validateSource();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'billing_cycle' => ['required', 'string', 'in:monthly,yearly'],
            'due_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'start_date' => ['required', 'date'],
            'category_id' => ['nullable', 'integer'],
            'bank_account_id' => ['nullable', 'integer'],
            'credit_card_id' => ['nullable', 'integer'],
            'auto_record' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        auth()->user()->subscriptions()->create($validated);

        session()->flash('message', 'Assinatura criada com sucesso.');
        $this->redirect(route('subscriptions.index'), navigate: true);
    }

    private function validateSource(): void
    {
        if (! $this->bank_account_id && ! $this->credit_card_id) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'bank_account_id' => 'Selecione uma conta bancaria ou cartao de credito.',
                'credit_card_id' => 'Selecione uma conta bancaria ou cartao de credito.',
            ]);
        }

        if ($this->bank_account_id && $this->credit_card_id) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'bank_account_id' => 'Selecione apenas uma fonte financeira por assinatura.',
                'credit_card_id' => 'Selecione apenas uma fonte financeira por assinatura.',
            ]);
        }
    }

    public function with(): array
    {
        return [
            'categories' => auth()->user()->categories()->orderBy('name')->get(),
            'bankAccounts' => auth()->user()->bankAccounts()->where('is_active', true)->orderBy('name')->get(),
            'creditCards' => auth()->user()->creditCards()->where('is_active', true)->orderBy('name')->get(),
        ];
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Nova Assinatura</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Cadastre contas recorrentes e fontes de pagamento.</p>
        </div>
        <flux:button href="{{ route('subscriptions.index') }}" wire:navigate variant="ghost">Voltar</flux:button>
    </div>

    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
        <form wire:submit="save" class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <flux:input wire:model="name" label="Nome" placeholder="Ex: Spotify" />
                <flux:input wire:model="description" label="Descricao" placeholder="Ex: Plano familia" />
                <flux:input wire:model="amount" type="number" step="0.01" label="Valor" placeholder="0.00" />

                <flux:select wire:model="billing_cycle" label="Ciclo">
                    <option value="monthly">Mensal</option>
                    <option value="yearly">Anual</option>
                </flux:select>

                <flux:input wire:model="due_day" type="number" min="1" max="31" label="Dia do vencimento" />
                <flux:input wire:model="start_date" type="date" label="Data inicial" />

                <flux:select wire:model="category_id" label="Categoria">
                    <option value="">Nenhuma</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="bank_account_id" label="Conta bancaria">
                    <option value="">Nenhuma</option>
                    @foreach($bankAccounts as $account)
                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="credit_card_id" label="Cartao de credito">
                    <option value="">Nenhum</option>
                    @foreach($creditCards as $card)
                        <option value="{{ $card->id }}">{{ $card->name }}</option>
                    @endforeach
                </flux:select>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <flux:checkbox wire:model="auto_record" label="Registrar automaticamente quando vencer" />
                <flux:checkbox wire:model="is_active" label="Assinatura ativa" />
            </div>

            <div class="flex justify-end gap-3">
                <flux:button href="{{ route('subscriptions.index') }}" wire:navigate variant="ghost">Cancelar</flux:button>
                <flux:button type="submit" variant="primary">Salvar Assinatura</flux:button>
            </div>
        </form>
    </div>
</div>

