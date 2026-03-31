<?php

use Livewire\Volt\Component;

new class extends Component {
    public ?int $category_id = null;
    public ?int $bank_account_id = null;
    public ?int $credit_card_id = null;
    public string $type = 'expense';
    public ?string $amount = null;
    public ?string $description = null;
    public string $frequency = 'monthly';
    public string $start_date;
    public ?string $end_date = null;
    public ?int $day_of_month = null;
    public ?int $day_of_week = null;

    public function mount(): void
    {
        $this->start_date = now()->format('Y-m-d');
        $this->day_of_month = now()->day;
        $this->day_of_week = now()->dayOfWeek;
    }

    public function save(): void
    {
        $this->validateSource();

        $rules = [
            'category_id' => ['nullable', 'integer'],
            'bank_account_id' => ['nullable', 'integer'],
            'credit_card_id' => ['nullable', 'integer'],
            'type' => ['required', 'string', 'in:income,expense'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:500'],
            'frequency' => ['required', 'string', 'in:daily,weekly,monthly,yearly'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
        ];

        if ($this->frequency === 'monthly') {
            $rules['day_of_month'] = ['required', 'integer', 'min:1', 'max:31'];
        }

        if ($this->frequency === 'weekly') {
            $rules['day_of_week'] = ['required', 'integer', 'min:0', 'max:6'];
        }

        $validated = $this->validate($rules);

        $recurring = auth()->user()->recurringTransactions()->create([
            'category_id' => $validated['category_id'] ?: null,
            'bank_account_id' => $validated['bank_account_id'] ?: null,
            'credit_card_id' => $validated['credit_card_id'] ?: null,
            'type' => $validated['type'],
            'amount' => $validated['amount'],
            'description' => $validated['description'] ?? null,
            'frequency' => $validated['frequency'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'] ?? null,
            'day_of_month' => $this->frequency === 'monthly' ? $this->day_of_month : null,
            'day_of_week' => $this->frequency === 'weekly' ? $this->day_of_week : null,
            'is_active' => true,
        ]);

        $startDate = \Carbon\Carbon::parse($this->start_date);
        $isFirstTime = ! $recurring->last_processed_at;
        $startDateIsTodayOrPast = $startDate->lte(now()->startOfDay());
        $shouldProcessNow = $isFirstTime && $startDateIsTodayOrPast;

        if (! $shouldProcessNow) {
            $shouldProcessNow = $recurring->shouldProcessToday();
        }

        if ($shouldProcessNow) {
            $recurring->user->transactions()->create([
                'category_id' => $recurring->category_id,
                'bank_account_id' => $recurring->bank_account_id,
                'credit_card_id' => $recurring->credit_card_id,
                'type' => $recurring->type,
                'amount' => $recurring->amount,
                'description' => $recurring->description ?? 'Transacao recorrente',
                'date' => now(),
                'metadata' => [
                    'recurring_transaction_id' => $recurring->id,
                    'source' => 'recurring_transaction',
                ],
            ]);

            $recurring->update(['last_processed_at' => now()]);
            session()->flash('message', 'Transacao recorrente criada e processada com sucesso!');
        } else {
            session()->flash('message', 'Transacao recorrente criada com sucesso!');
        }

        $this->redirect(route('recurring-transactions.index'), navigate: true);
    }

    private function validateSource(): void
    {
        if ($this->bank_account_id && $this->credit_card_id) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'bank_account_id' => 'Selecione apenas uma fonte financeira.',
                'credit_card_id' => 'Selecione apenas uma fonte financeira.',
            ]);
        }

        if ($this->bank_account_id && ! auth()->user()->bankAccounts()->whereKey($this->bank_account_id)->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'bank_account_id' => 'A conta bancaria selecionada nao existe.',
            ]);
        }

        if ($this->credit_card_id && ! auth()->user()->creditCards()->whereKey($this->credit_card_id)->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'credit_card_id' => 'O cartao de credito selecionado nao existe.',
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
            <h1 class="text-2xl font-bold">Nova Transacao Recorrente</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Configure uma transacao que se repete automaticamente.</p>
        </div>
        <flux:button href="{{ route('recurring-transactions.index') }}" wire:navigate variant="ghost">Voltar</flux:button>
    </div>

    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
        <form wire:submit="save" class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <flux:select wire:model="type" label="Tipo">
                    <option value="expense">Despesa</option>
                    <option value="income">Receita</option>
                </flux:select>

                <flux:input wire:model="amount" type="number" step="0.01" label="Valor" placeholder="0.00" />

                <flux:select wire:model="category_id" label="Categoria">
                    <option value="">Nenhuma</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="description" label="Descricao" placeholder="Ex: Aluguel" />

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

                <flux:select wire:model.live="frequency" label="Frequencia">
                    <option value="daily">Diaria</option>
                    <option value="weekly">Semanal</option>
                    <option value="monthly">Mensal</option>
                    <option value="yearly">Anual</option>
                </flux:select>

                <flux:input wire:model="start_date" type="date" label="Data inicial" />
                <flux:input wire:model="end_date" type="date" label="Data final (opcional)" />

                @if($frequency === 'monthly')
                    <flux:input wire:model="day_of_month" type="number" min="1" max="31" label="Dia do mes" />
                @endif

                @if($frequency === 'weekly')
                    <flux:select wire:model="day_of_week" label="Dia da semana">
                        <option value="0">Domingo</option>
                        <option value="1">Segunda-feira</option>
                        <option value="2">Terca-feira</option>
                        <option value="3">Quarta-feira</option>
                        <option value="4">Quinta-feira</option>
                        <option value="5">Sexta-feira</option>
                        <option value="6">Sabado</option>
                    </flux:select>
                @endif
            </div>

            <div class="flex justify-end gap-3">
                <flux:button href="{{ route('recurring-transactions.index') }}" wire:navigate variant="ghost">Cancelar</flux:button>
                <flux:button type="submit" variant="primary">Salvar Transacao</flux:button>
            </div>
        </form>
    </div>
</div>
