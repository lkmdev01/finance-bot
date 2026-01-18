<?php

use Livewire\Volt\Component;

new class extends Component {
    public string $name = '';
    public ?string $description = null;
    public ?string $target_amount = null;
    public ?string $target_date = null;

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'target_amount' => ['required', 'numeric', 'min:0.01'],
            'target_date' => ['nullable', 'date', 'after_or_equal:today'],
        ], [
            'name.required' => 'O nome da meta é obrigatório.',
            'target_amount.required' => 'O valor da meta é obrigatório.',
            'target_amount.numeric' => 'O valor deve ser um número.',
            'target_amount.min' => 'O valor deve ser maior que zero.',
            'target_date.after_or_equal' => 'A data deve ser hoje ou no futuro.',
        ]);

        auth()->user()->savingsGoals()->create([
            'name' => $this->name,
            'description' => $this->description,
            'target_amount' => $this->target_amount,
            'target_date' => $this->target_date,
        ]);

        session()->flash('message', 'Meta criada com sucesso!');
        
        $this->redirect(route('savings-goals.index'), navigate: true);
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Nova Meta de Economia</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Defina uma nova meta para economizar</p>
        </div>
        <flux:button href="{{ route('savings-goals.index') }}" wire:navigate variant="ghost">
            Voltar
        </flux:button>
    </div>

    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
        <form wire:submit="save" class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <flux:field>
                    <flux:label>Nome da Meta</flux:label>
                    <flux:input wire:model="name" placeholder="Ex: Viagem para Europa" required />
                    <flux:error name="name" />
                </flux:field>

                <flux:field>
                    <flux:label>Valor da Meta</flux:label>
                    <div 
                        x-data="{ 
                            displayValue: '',
                            init() {
                                if (this.$wire.target_amount) {
                                    this.displayValue = this.formatCurrency(this.$wire.target_amount);
                                }
                                this.$watch('$wire.target_amount', value => {
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
                                    this.$wire.set('target_amount', '');
                                    return;
                                }
                                let amount = (parseInt(numbers) / 100).toFixed(2);
                                this.displayValue = this.formatCurrency(amount);
                                this.$wire.set('target_amount', amount);
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
                    <flux:error name="target_amount" />
                </flux:field>

                <flux:field>
                    <flux:label>Data Alvo (opcional)</flux:label>
                    <flux:input type="date" wire:model="target_date" min="{{ now()->format('Y-m-d') }}" />
                    <flux:description>Data limite para alcançar a meta</flux:description>
                    <flux:error name="target_date" />
                </flux:field>

                <flux:field class="md:col-span-2">
                    <flux:label>Descrição (opcional)</flux:label>
                    <flux:textarea 
                        wire:model="description" 
                        placeholder="Descreva sua meta..."
                        rows="3"
                    />
                    <flux:error name="description" />
                </flux:field>
            </div>

            <div class="flex items-center justify-end gap-3">
                <flux:button href="{{ route('savings-goals.index') }}" wire:navigate variant="ghost">
                    Cancelar
                </flux:button>
                <flux:button type="submit" variant="primary">
                    Criar Meta
                </flux:button>
            </div>
        </form>
    </div>
</div>
