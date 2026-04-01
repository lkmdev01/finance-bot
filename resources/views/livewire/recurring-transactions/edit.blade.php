<?php

use App\Models\RecurringTransaction;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public ?RecurringTransaction $recurring = null;

    public ?int $category_id = null;
    public ?int $bank_account_id = null;
    public ?int $credit_card_id = null;
    public string $source_type = 'none';
    public string $type = 'expense';
    public ?string $amount = null;
    public ?string $description = null;
    public string $frequency = 'monthly';
    public string $start_date;
    public ?string $end_date = null;
    public ?int $day_of_month = null;
    public ?int $day_of_week = null;

    public function mount(?RecurringTransaction $recurring = null): void
    {
        if (! $recurring) {
            $recurring = Auth::user()->recurringTransactions()->findOrFail(request()->route('recurring-transaction'));
        }

        $this->recurring = $recurring;
        $this->category_id = $recurring->category_id;
        $this->bank_account_id = $recurring->bank_account_id;
        $this->credit_card_id = $recurring->credit_card_id;
        $this->source_type = $recurring->bank_account_id ? 'bank_account' : ($recurring->credit_card_id ? 'credit_card' : 'none');
        $this->type = $recurring->type;
        $this->amount = (string) $recurring->amount;
        $this->description = $recurring->description;
        $this->frequency = $recurring->frequency;
        $this->start_date = $recurring->start_date->format('Y-m-d');
        $this->end_date = $recurring->end_date?->format('Y-m-d');
        $this->day_of_month = $recurring->day_of_month;
        $this->day_of_week = $recurring->day_of_week;
    }

    public function setSourceType(string $type): void
    {
        $this->source_type = $type;

        if ($type !== 'bank_account') {
            $this->bank_account_id = null;
        }

        if ($type !== 'credit_card') {
            $this->credit_card_id = null;
        }
    }

    public function save(): void
    {
        $this->normalizeSource();
        $this->válidateSource();

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

        $válidated = $this->válidate($rules);

        $this->recurring->update([
            'category_id' => $válidated['category_id'] ?: null,
            'bank_account_id' => $válidated['bank_account_id'] ?: null,
            'credit_card_id' => $válidated['credit_card_id'] ?: null,
            'type' => $válidated['type'],
            'amount' => $válidated['amount'],
            'description' => $válidated['description'] ?? null,
            'frequency' => $válidated['frequency'],
            'start_date' => $válidated['start_date'],
            'end_date' => $válidated['end_date'] ?? null,
            'day_of_month' => $this->frequency === 'monthly' ? $this->day_of_month : null,
            'day_of_week' => $this->frequency === 'weekly' ? $this->day_of_week : null,
        ]);

        session()->flash('message', 'transação recorrente atualizada com sucesso!');

        $this->redirect(route('recurring-transactions.index'), navigate: true);
    }

    private function normalizeSource(): void
    {
        if ($this->source_type === 'bank_account') {
            $this->credit_card_id = null;
            return;
        }

        if ($this->source_type === 'credit_card') {
            $this->bank_account_id = null;
            return;
        }

        $this->bank_account_id = null;
        $this->credit_card_id = null;
    }

    private function válidateSource(): void
    {
        if ($this->source_type === 'bank_account') {
            if (! $this->bank_account_id) {
                throw \Illuminate\válidation\válidationException::withMessages([
                    'bank_account_id' => 'Selecione uma conta bancária.',
                ]);
            }

            if (! auth()->user()->bankAccounts()->whereKey($this->bank_account_id)->exists()) {
                throw \Illuminate\válidation\válidationException::withMessages([
                    'bank_account_id' => 'A conta bancária selecionada não existe.',
                ]);
            }
        }

        if ($this->source_type === 'credit_card') {
            if (! $this->credit_card_id) {
                throw \Illuminate\válidation\válidationException::withMessages([
                    'credit_card_id' => 'Selecione um cartão de crédito.',
                ]);
            }

            if (! auth()->user()->creditCards()->whereKey($this->credit_card_id)->exists()) {
                throw \Illuminate\válidation\válidationException::withMessages([
                    'credit_card_id' => 'O cartão de crédito selecionado não existe.',
                ]);
            }
        }
    }

    public function with(): array
    {
        return [
            'categories' => Auth::user()->categories()->orderBy('name')->get(),
            'bankAccounts' => Auth::user()->bankAccounts()->where('is_active', true)->orderBy('name')->get(),
            'creditCards' => Auth::user()->creditCards()->where('is_active', true)->orderBy('name')->get(),
        ];
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Editar transação Recorrente</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Atualize os dados da transação recorrente.</p>
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

                <flux:input wire:model="description" label="Descrição" placeholder="Ex: Aluguel" />
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-950/50">
                <div class="flex items-center justify-between gap-3 mb-4">
                    <div>
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Fonte financeira</h2>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Escolha conta ou cartão. O campo aparece só quando a fonte for selecionada.</p>
                    </div>
                    @if($source_type !== 'none')
                        <button type="button" wire:click="setSourceType('none')" class="text-xs text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100">Limpar</button>
                    @endif
                </div>

                <div class="flex flex-wrap gap-3">
                    <button type="button" wire:click="setSourceType('bank_account')" class="inline-flex items-center gap-2 rounded-2xl border px-4 py-3 text-sm font-medium transition {{ $source_type === 'bank_account' ? 'border-sky-500 bg-sky-500/10 text-sky-700 dark:text-sky-300' : 'border-zinc-200 bg-white text-zinc-700 hover:border-zinc-300 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300' }}">
                        <span class="inline-block h-2.5 w-2.5 rounded-full {{ $source_type === 'bank_account' ? 'bg-sky-500' : 'bg-zinc-400' }}"></span>
                        Conta bancária
                    </button>
                    <button type="button" wire:click="setSourceType('credit_card')" class="inline-flex items-center gap-2 rounded-2xl border px-4 py-3 text-sm font-medium transition {{ $source_type === 'credit_card' ? 'border-emerald-500 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : 'border-zinc-200 bg-white text-zinc-700 hover:border-zinc-300 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300' }}">
                        <span class="inline-block h-2.5 w-2.5 rounded-full {{ $source_type === 'credit_card' ? 'bg-emerald-500' : 'bg-zinc-400' }}"></span>
                        cartão de crédito
                    </button>
                </div>

                @if($source_type === 'bank_account')
                    <div class="mt-4">
                        <flux:select wire:model="bank_account_id" label="Conta bancária">
                            <option value="">Selecione uma conta</option>
                            @foreach($bankAccounts as $account)
                                <option value="{{ $account->id }}">{{ $account->name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="bank_account_id" />
                    </div>
                @endif

                @if($source_type === 'credit_card')
                    <div class="mt-4">
                        <flux:select wire:model="credit_card_id" label="cartão de crédito">
                            <option value="">Selecione um cartão</option>
                            @foreach($creditCards as $card)
                                <option value="{{ $card->id }}">{{ $card->name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="credit_card_id" />
                    </div>
                @endif
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <flux:select wire:model.live="frequency" label="Frequência">
                    <option value="daily">Diária</option>
                    <option value="weekly">Semanal</option>
                    <option value="monthly">Mensal</option>
                    <option value="yearly">Anual</option>
                </flux:select>

                <flux:input wire:model="start_date" type="date" label="Data inicial" />
                <flux:input wire:model="end_date" type="date" label="Data final (opcional)" />

                @if($frequency === 'monthly')
                    <flux:input wire:model="day_of_month" type="number" min="1" max="31" label="Dia do mês" />
                @endif

                @if($frequency === 'weekly')
                    <flux:select wire:model="day_of_week" label="Dia da semana">
                        <option value="0">Domingo</option>
                        <option value="1">Segunda-feira</option>
                        <option value="2">Terça-feira</option>
                        <option value="3">Quarta-feira</option>
                        <option value="4">Quinta-feira</option>
                        <option value="5">Sexta-feira</option>
                        <option value="6">Sábado</option>
                    </flux:select>
                @endif
            </div>

            <div class="flex justify-end gap-3">
                <flux:button href="{{ route('recurring-transactions.index') }}" wire:navigate variant="ghost">Cancelar</flux:button>
                <flux:button type="submit" variant="primary">Atualizar transação</flux:button>
            </div>
        </form>
    </div>
</div>
