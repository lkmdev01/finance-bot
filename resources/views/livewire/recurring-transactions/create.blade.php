<?php

use Livewire\Volt\Component;

new class extends Component {
    public int $category_id = 0;
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
        // Converter category_id 0 para null antes da validação
        if ($this->category_id == 0) {
            $this->category_id = null;
        }

        $rules = [
            'category_id' => ['nullable', 'integer', function ($attribute, $value, $fail) {
                if ($value !== null && $value > 0 && !auth()->user()->categories()->where('id', $value)->exists()) {
                    $fail('A categoria selecionada não existe.');
                }
            }],
            'type' => ['required', 'string', 'in:income,expense'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:500'],
            'frequency' => ['required', 'string', 'in:daily,weekly,monthly,yearly'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
        ];

        $messages = [
            'type.required' => 'O tipo é obrigatório.',
            'amount.required' => 'O valor é obrigatório.',
            'amount.numeric' => 'O valor deve ser um número.',
            'amount.min' => 'O valor deve ser maior que zero.',
            'frequency.required' => 'A frequência é obrigatória.',
            'start_date.required' => 'A data de início é obrigatória.',
            'end_date.after' => 'A data de término deve ser após a data de início.',
        ];

        if ($this->frequency === 'monthly') {
            $rules['day_of_month'] = ['required', 'integer', 'min:1', 'max:31'];
            $messages['day_of_month.required'] = 'O dia do mês é obrigatório para frequência mensal.';
        }

        if ($this->frequency === 'weekly') {
            $rules['day_of_week'] = ['required', 'integer', 'min:0', 'max:6'];
            $messages['day_of_week.required'] = 'O dia da semana é obrigatório para frequência semanal.';
        }

        $validated = $this->validate($rules, $messages);

        $recurring = auth()->user()->recurringTransactions()->create([
            'category_id' => $this->category_id ?: null,
            'type' => $this->type,
            'amount' => $this->amount,
            'description' => $this->description,
            'frequency' => $this->frequency,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'day_of_month' => $this->frequency === 'monthly' ? $this->day_of_month : null,
            'day_of_week' => $this->frequency === 'weekly' ? $this->day_of_week : null,
            'is_active' => true,
        ]);

        // Processar imediatamente se:
        // 1. A data de início for hoje (ou anterior) E ainda não foi processada (primeira vez)
        // OU
        // 2. A frequência permite processar hoje (daily sempre, weekly/monthly/yearly se for o dia correto)
        $startDate = \Carbon\Carbon::parse($this->start_date);
        $isFirstTime = !$recurring->last_processed_at;
        $startDateIsTodayOrPast = $startDate->lte(now()->startOfDay());
        
        // Se for a primeira vez e a data de início for hoje ou anterior, processa imediatamente
        $shouldProcessNow = $isFirstTime && $startDateIsTodayOrPast;
        
        // Se não for a primeira vez, verifica se deve processar hoje pela frequência
        if (!$shouldProcessNow) {
            $shouldProcessNow = $recurring->shouldProcessToday();
        }

        if ($shouldProcessNow) {
            $recurring->user->transactions()->create([
                'category_id' => $recurring->category_id,
                'type' => $recurring->type,
                'amount' => $recurring->amount,
                'description' => $recurring->description ?? 'Transação recorrente',
                'date' => now(),
                'metadata' => [
                    'recurring_transaction_id' => $recurring->id,
                ],
            ]);

            $recurring->update(['last_processed_at' => now()]);
            session()->flash('message', 'Transação recorrente criada e processada com sucesso!');
        } else {
            session()->flash('message', 'Transação recorrente criada com sucesso!');
        }
        
        $this->redirect(route('recurring-transactions.index'), navigate: true);
    }

    public function with(): array
    {
        return [
            'categories' => auth()->user()->categories()->orderBy('name')->get(),
        ];
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Nova Transação Recorrente</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Configure uma transação que se repete automaticamente</p>
        </div>
        <flux:button href="{{ route('recurring-transactions.index') }}" wire:navigate variant="ghost">
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
                    <flux:label>Categoria (opcional)</flux:label>
                    <flux:select wire:model="category_id" placeholder="Selecione uma categoria">
                        <option value="">Nenhuma</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="category_id" />
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
                    <flux:label>Descrição (opcional)</flux:label>
                    <flux:input wire:model="description" placeholder="Ex: Salário, Aluguel..." />
                    <flux:error name="description" />
                </flux:field>

                <flux:field>
                    <flux:label>Frequência</flux:label>
                    <flux:select wire:model.live="frequency" required>
                        <option value="daily">Diária</option>
                        <option value="weekly">Semanal</option>
                        <option value="monthly">Mensal</option>
                        <option value="yearly">Anual</option>
                    </flux:select>
                    <flux:error name="frequency" />
                </flux:field>

                @if($frequency === 'weekly')
                    <flux:field>
                        <flux:label>Dia da Semana</flux:label>
                        <flux:select wire:model="day_of_week" required>
                            <option value="0">Domingo</option>
                            <option value="1">Segunda-feira</option>
                            <option value="2">Terça-feira</option>
                            <option value="3">Quarta-feira</option>
                            <option value="4">Quinta-feira</option>
                            <option value="5">Sexta-feira</option>
                            <option value="6">Sábado</option>
                        </flux:select>
                        <flux:error name="day_of_week" />
                    </flux:field>
                @endif

                @if($frequency === 'monthly')
                    <flux:field>
                        <flux:label>Dia do Mês</flux:label>
                        <flux:input type="number" wire:model="day_of_month" min="1" max="31" required />
                        <flux:error name="day_of_month" />
                    </flux:field>
                @endif

                <flux:field>
                    <flux:label>Data de Início</flux:label>
                    <flux:input type="date" wire:model="start_date" required />
                    <flux:error name="start_date" />
                </flux:field>

                <flux:field>
                    <flux:label>Data de Término (opcional)</flux:label>
                    <flux:input type="date" wire:model="end_date" />
                    <flux:description>Deixe em branco para continuar indefinidamente</flux:description>
                    <flux:error name="end_date" />
                </flux:field>
            </div>

            <div class="flex items-center justify-end gap-3">
                <flux:button href="{{ route('recurring-transactions.index') }}" wire:navigate variant="ghost">
                    Cancelar
                </flux:button>
                <flux:button type="submit" variant="primary">
                    Criar Transação Recorrente
                </flux:button>
            </div>
        </form>
    </div>
</div>
