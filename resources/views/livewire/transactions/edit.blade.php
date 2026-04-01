<?php

use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public ?Transaction $transaction = null;

    public string $type;
    public ?string $amount = null;
    public ?string $description = null;
    public string $date;
    public ?int $category_id = null;
    public ?int $bank_account_id = null;
    public ?int $credit_card_id = null;
    public array $selectedTags = [];

    public function mount(?Transaction $transaction = null): void
    {
        if (! $transaction) {
            $transaction = Transaction::findOrFail(request()->route('transaction'));
        }

        $this->transaction = $transaction;
        $this->type = $transaction->type;
        $this->amount = (string) $transaction->amount;
        $this->description = $transaction->description;
        $this->date = $transaction->date->format('Y-m-d');
        $this->category_id = $transaction->category_id;
        $this->bank_account_id = $transaction->bank_account_id;
        $this->credit_card_id = $transaction->credit_card_id;
        $this->selectedTags = $transaction->tags->pluck('id')->toArray();
    }

    public function save(): void
    {
        $this->válidateSource();

        $válidated = $this->válidate([
            'type' => ['required', 'string', 'in:income,expense'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'bank_account_id' => ['nullable', 'integer'],
            'credit_card_id' => ['nullable', 'integer'],
            'selectedTags' => ['nullable', 'array'],
            'selectedTags.*' => ['exists:tags,id'],
        ], [
            'type.required' => 'O tipo da transação e obrigatório.',
            'type.in' => 'O tipo deve ser receita ou despesa.',
            'amount.required' => 'O valor e obrigatório.',
            'amount.numeric' => 'O valor deve ser um número.',
            'amount.min' => 'O valor deve ser maior que zero.',
            'date.required' => 'A data e obrigatória.',
            'date.date' => 'A data deve ser válida.',
            'category_id.exists' => 'A categoria selecionada não existe.',
        ]);

        $this->transaction->update($válidated);
        $this->transaction->tags()->sync($this->selectedTags ?? []);

        session()->flash('message', 'transação atualizada com sucesso!');

        $this->redirect(route('transactions.index'), navigate: true);
    }

    private function válidateSource(): void
    {
        if ($this->bank_account_id && $this->credit_card_id) {
            throw \Illuminate\válidation\válidationException::withMessages([
                'bank_account_id' => 'Selecione apenas uma fonte financeira.',
                'credit_card_id' => 'Selecione apenas uma fonte financeira.',
            ]);
        }

        if ($this->bank_account_id && ! Auth::user()->bankAccounts()->whereKey($this->bank_account_id)->exists()) {
            throw \Illuminate\válidation\válidationException::withMessages([
                'bank_account_id' => 'A conta bancária selecionada não existe.',
            ]);
        }

        if ($this->credit_card_id && ! Auth::user()->creditCards()->whereKey($this->credit_card_id)->exists()) {
            throw \Illuminate\válidation\válidationException::withMessages([
                'credit_card_id' => 'O cartão de crédito selecionado não existe.',
            ]);
        }
    }

    public function with(): array
    {
        return [
            'categories' => Auth::user()->categories()
                ->where('type', $this->type)
                ->orderBy('name')
                ->get(),
            'bankAccounts' => Auth::user()->bankAccounts()->where('is_active', true)->orderBy('name')->get(),
            'creditCards' => Auth::user()->creditCards()->where('is_active', true)->orderBy('name')->get(),
            'tags' => Auth::user()->tags()->orderBy('name')->get(),
        ];
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Editar transação</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Atualize os dados da transação</p>
        </div>
        <flux:button href="{{ route('transactions.index') }}" wire:navigate variant="ghost">
            Voltar
        </flux:button>
    </div>

    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
        <form wire:submit="save" class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <flux:field>
                    <flux:label>Tipo</flux:label>
                    <flux:radio.group wire:model.live="type">
                        <flux:radio value="income" label="Receita" />
                        <flux:radio value="expense" label="Despesa" />
                    </flux:radio.group>
                    <flux:error name="type" />
                </flux:field>

                <flux:field>
                    <flux:label>Valor</flux:label>
                    <div
                        x-data="{
                            displayValue: '',
                            init() {
                                if (this.$wire.amount) {
                                    this.displayValue = this.formatCurrency(this.$wire.amount);
                                }
                                this.$watch('$wire.amount', value => {
                                    if (value && value !== this.unformatCurrency(this.displayValue)) {
                                        this.displayValue = this.formatCurrency(value);
                                    }
                                });
                            },
                            formatCurrency(value) {
                                if (!value) return '';
                                let numValue = parseFloat(value);
                                if (isNaN(numValue)) return '';
                                return new Intl.NumberFormat('pt-BR', {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2
                                }).format(numValue);
                            },
                            unformatCurrency(value) {
                                if (!value) return '';
                                let numbers = value.replace(/\D/g, '');
                                if (!numbers) return '';
                                return (parseInt(numbers) / 100).toFixed(2);
                            },
                            handleInput(event) {
                                let input = event.target.value;
                                let numbers = input.replace(/\D/g, '');
                                if (!numbers) {
                                    this.displayValue = '';
                                    this.$wire.set('amount', '');
                                    return;
                                }
                                let amount = (parseInt(numbers) / 100).toFixed(2);
                                this.displayValue = this.formatCurrency(amount);
                                this.$wire.set('amount', amount);
                            }
                        }"
                    >
                        <flux:input
                            type="text"
                            x-model="displayValue"
                            x-on:input="handleInput($event)"
                            placeholder="0,00"
                            required
                        />
                    </div>
                    <flux:error name="amount" />
                </flux:field>

                <flux:field>
                    <flux:label>Data</flux:label>
                    <flux:input type="date" wire:model="date" required />
                    <flux:error name="date" />
                </flux:field>

                <flux:field>
                    <flux:label>Categoria</flux:label>
                    <flux:select wire:model="category_id" placeholder="Selecione uma categoria">
                        <option value="">Nenhuma</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="category_id" />
                    @if($categories->isEmpty())
                        <flux:description>
                            Nenhuma categoria encontrada para este tipo.
                            <a href="{{ route('categories.create') }}" wire:navigate class="text-primary hover:underline">
                                Criar categoria
                            </a>
                        </flux:description>
                    @endif
                </flux:field>

                <flux:field>
                    <flux:label>Conta bancária</flux:label>
                    <flux:select wire:model="bank_account_id" placeholder="Selecione uma conta">
                        <option value="">Nenhuma</option>
                        @foreach($bankAccounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="bank_account_id" />
                </flux:field>

                <flux:field>
                    <flux:label>cartão de crédito</flux:label>
                    <flux:select wire:model="credit_card_id" placeholder="Selecione um cartão">
                        <option value="">Nenhum</option>
                        @foreach($creditCards as $card)
                            <option value="{{ $card->id }}">{{ $card->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="credit_card_id" />
                </flux:field>

                <flux:field class="md:col-span-2">
                    <flux:label>Descrição</flux:label>
                    <flux:textarea
                        wire:model="description"
                        placeholder="Descrição da transação (opcional)"
                        rows="3"
                    />
                    <flux:error name="description" />
                </flux:field>

                <flux:field class="md:col-span-2">
                    <flux:label>Tags (opcional)</flux:label>
                    <flux:description>Selecione tags para organizar suas transações</flux:description>
                    <div class="space-y-2 max-h-32 overflow-y-auto border border-zinc-200 dark:border-zinc-700 rounded-lg p-3">
                        @forelse($tags as $tag)
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input
                                    type="checkbox"
                                    wire:model="selectedTags"
                                    value="{{ $tag->id }}"
                                    class="rounded border-zinc-300 text-blue-600 focus:ring-blue-500"
                                />
                                <span
                                    class="px-2 py-1 text-xs font-medium rounded"
                                    style="background-color: {{ $tag->color }}20; color: {{ $tag->color }}"
                                >
                                    {{ $tag->name }}
                                </span>
                            </label>
                        @empty
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                Nenhuma tag criada.
                                <a href="{{ route('tags.index') }}" wire:navigate class="text-primary hover:underline">
                                    Criar tags
                                </a>
                            </p>
                        @endforelse
                    </div>
                    <flux:error name="selectedTags" />
                </flux:field>
            </div>

            <div class="flex items-center justify-end gap-3">
                <flux:button href="{{ route('transactions.index') }}" wire:navigate variant="ghost">
                    Cancelar
                </flux:button>
                <flux:button type="submit" variant="primary">
                    Atualizar transação
                </flux:button>
            </div>
        </form>
    </div>
</div>

