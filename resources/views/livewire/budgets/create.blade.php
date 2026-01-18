<?php

use App\Http\Requests\StoreBudgetRequest;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public int $category_id = 0;
    public ?string $amount = null;
    public string $period = 'monthly';
    public int $year;
    public ?int $month = null;

    public function mount(): void
    {
        $this->year = now()->year;
        $this->month = now()->month;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'period' => ['required', 'string', 'in:monthly,yearly'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ], [
            'category_id.required' => 'A categoria é obrigatória.',
            'category_id.exists' => 'A categoria selecionada não existe.',
            'amount.required' => 'O valor é obrigatório.',
            'amount.numeric' => 'O valor deve ser um número.',
            'amount.min' => 'O valor deve ser maior que zero.',
            'period.required' => 'O período é obrigatório.',
            'period.in' => 'O período deve ser mensal ou anual.',
            'year.required' => 'O ano é obrigatório.',
            'month.integer' => 'O mês deve ser um número entre 1 e 12.',
        ]);

        $validated['month'] = $this->period === 'monthly' ? $this->month : null;

        Auth::user()->budgets()->create($validated);

        session()->flash('message', 'Orçamento criado com sucesso!');
        
        $this->redirect(route('budgets.index'), navigate: true);
    }

    public function with(): array
    {
        return [
            'categories' => Auth::user()->categories()
                ->where('type', 'expense')
                ->orderBy('name')
                ->get(),
        ];
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Novo Orçamento</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Defina um limite de gastos para uma categoria</p>
        </div>
        <flux:button href="{{ route('budgets.index') }}" wire:navigate variant="ghost">
            Voltar
        </flux:button>
    </div>

    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
        <form wire:submit="save" class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <flux:field>
                    <flux:label>Categoria</flux:label>
                    <flux:select wire:model="category_id" placeholder="Selecione uma categoria" required>
                        <option value="">Selecione...</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="category_id" />
                    @if($categories->isEmpty())
                        <flux:description>
                            Nenhuma categoria de despesa encontrada. 
                            <a href="{{ route('categories.create') }}" wire:navigate class="text-primary hover:underline">
                                Criar categoria
                            </a>
                        </flux:description>
                    @endif
                </flux:field>

                <flux:field>
                    <flux:label>Valor do Orçamento</flux:label>
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
                    <flux:label>Período</flux:label>
                    <flux:radio.group wire:model.live="period">
                        <flux:radio value="monthly" label="Mensal" />
                        <flux:radio value="yearly" label="Anual" />
                    </flux:radio.group>
                    <flux:error name="period" />
                </flux:field>

                <flux:field>
                    <flux:label>Ano</flux:label>
                    <flux:input type="number" wire:model="year" min="2020" max="2100" required />
                    <flux:error name="year" />
                </flux:field>

                @if($period === 'monthly')
                    <flux:field>
                        <flux:label>Mês</flux:label>
                        <flux:select wire:model="month" required>
                            @for($m = 1; $m <= 12; $m++)
                                <option value="{{ $m }}">{{ \Carbon\Carbon::create(null, $m, 1)->locale('pt_BR')->monthName }}</option>
                            @endfor
                        </flux:select>
                        <flux:error name="month" />
                    </flux:field>
                @endif
            </div>

            <div class="flex items-center justify-end gap-3">
                <flux:button href="{{ route('budgets.index') }}" wire:navigate variant="ghost">
                    Cancelar
                </flux:button>
                <flux:button type="submit" variant="primary">
                    Criar Orçamento
                </flux:button>
            </div>
        </form>
    </div>
</div>
