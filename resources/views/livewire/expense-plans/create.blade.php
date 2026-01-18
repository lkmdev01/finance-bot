<?php

use App\Models\Category;
use App\Models\ExpensePlan;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public string $name = '';
    public ?string $description = null;
    public ?string $plannedAmount = null;
    public string $startDate;
    public string $endDate;
    public array $selectedCategories = [];

    public function mount(): void
    {
        $this->startDate = now()->format('Y-m-d');
        $this->endDate = now()->addMonth()->format('Y-m-d');
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'plannedAmount' => ['required', 'numeric', 'min:0.01'],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date', 'after:startDate'],
            'selectedCategories' => ['nullable', 'array'],
        ], [
            'name.required' => 'O nome do plano é obrigatório.',
            'plannedAmount.required' => 'O valor planejado é obrigatório.',
            'plannedAmount.numeric' => 'O valor deve ser um número.',
            'plannedAmount.min' => 'O valor deve ser maior que zero.',
            'startDate.required' => 'A data de início é obrigatória.',
            'endDate.required' => 'A data de término é obrigatória.',
            'endDate.after' => 'A data de término deve ser posterior à data de início.',
        ]);

        $plan = Auth::user()->expensePlans()->create([
            'name' => $this->name,
            'description' => $this->description,
            'planned_amount' => (float) $this->plannedAmount,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'categories' => $this->selectedCategories,
        ]);

        // Atualizar valor gasto inicial
        $plan->updateSpentAmount();

        session()->flash('message', 'Plano de despesas criado com sucesso!');
        $this->redirect(route('expense-plans.index'), navigate: true);
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
            <h1 class="text-2xl font-bold">Novo Plano de Despesas</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Crie um plano para controlar seus gastos</p>
        </div>
        <flux:button href="{{ route('expense-plans.index') }}" wire:navigate variant="ghost">
            Voltar
        </flux:button>
    </div>

    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
        <form wire:submit="save" class="space-y-6">
            <flux:field>
                <flux:label>Nome do Plano</flux:label>
                <flux:input wire:model="name" placeholder="Ex: Férias de Verão" required />
                <flux:error name="name" />
            </flux:field>

            <flux:field>
                <flux:label>Descrição</flux:label>
                <flux:textarea wire:model="description" placeholder="Descrição do plano (opcional)" rows="3" />
                <flux:error name="description" />
            </flux:field>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <flux:field>
                    <flux:label>Valor Planejado</flux:label>
                    <div 
                        x-data="{ 
                            displayValue: '',
                            init() {
                                if (this.$wire.plannedAmount) {
                                    this.displayValue = this.formatCurrency(this.$wire.plannedAmount);
                                }
                                this.$watch('$wire.plannedAmount', value => {
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
                                    this.$wire.set('plannedAmount', '');
                                    return;
                                }
                                let amount = (parseInt(numbers) / 100).toFixed(2);
                                this.displayValue = this.formatCurrency(amount);
                                this.$wire.set('plannedAmount', amount);
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
                    <flux:error name="plannedAmount" />
                </flux:field>

                <flux:field>
                    <flux:label>Data de Início</flux:label>
                    <flux:input type="date" wire:model="startDate" required />
                    <flux:error name="startDate" />
                </flux:field>

                <flux:field>
                    <flux:label>Data de Término</flux:label>
                    <flux:input type="date" wire:model="endDate" required />
                    <flux:error name="endDate" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>Categorias (Opcional)</flux:label>
                <flux:description>
                    Selecione categorias específicas para este plano. Deixe vazio para incluir todas as categorias.
                </flux:description>
                <div class="space-y-2 max-h-48 overflow-y-auto border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                    @foreach($categories as $category)
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input 
                                type="checkbox" 
                                wire:model="selectedCategories"
                                value="{{ $category->id }}"
                                class="rounded border-zinc-300 text-blue-600 focus:ring-blue-500"
                            />
                            <span class="text-sm">{{ $category->name }}</span>
                        </label>
                    @endforeach
                    @if($categories->isEmpty())
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">Nenhuma categoria de despesa encontrada.</p>
                    @endif
                </div>
                <flux:error name="selectedCategories" />
            </flux:field>

            <div class="flex items-center justify-end gap-3">
                <flux:button href="{{ route('expense-plans.index') }}" wire:navigate variant="ghost">
                    Cancelar
                </flux:button>
                <flux:button type="submit" variant="primary">
                    Criar Plano
                </flux:button>
            </div>
        </form>
    </div>
</div>
