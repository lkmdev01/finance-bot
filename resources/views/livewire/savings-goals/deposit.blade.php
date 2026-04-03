<?php

use App\Models\SavingsGoal;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public ?SavingsGoal $goal = null;
    
    public ?string $amount = null;
    public ?string $description = null;
    public ?string $deposit_date = null;

    public function mount(?SavingsGoal $goal = null): void
    {
        if (!$goal) {
            $goal = Auth::user()->savingsGoals()->findOrFail(request()->route('savings-goal'));
        }
        $this->goal = $goal;
        $this->deposit_date = now()->format('Y-m-d');
    }

    public function getAvailableBalance(): float
    {
        $user = Auth::user();
        $totalIncome = (float) $user->transactions()
            ->where('type', 'income')
            ->sum('amount');
        
        // Excluir transaÃ§Ãµes de depÃ³sito em metas (jÃ¡ sÃ£o contadas separadamente)
        $allExpenses = $user->transactions()
            ->where('type', 'expense')
            ->get();
        
        $expensesWithoutSavings = $allExpenses->filter(function ($transaction) {
            $metadata = $transaction->metadata ?? [];
            return !isset($metadata['savings_goal_deposit_id']);
        });
        
        $totalExpenses = (float) $expensesWithoutSavings->sum('amount');
        
        $totalSavings = (float) $user->savingsGoals()
            ->with('deposits')
            ->get()
            ->sum(fn($goal) => $goal->deposits->sum('amount'));
        
        return $totalIncome - $totalExpenses - $totalSavings;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:500'],
            'deposit_date' => ['required', 'date', 'before_or_equal:today'],
        ], [
            'amount.required' => 'O valor do depÃ³sito Ã© obrigatÃ³rio.',
            'amount.numeric' => 'O valor deve ser um nÃºmero.',
            'amount.min' => 'O valor deve ser maior que zero.',
            'deposit_date.required' => 'A data do depÃ³sito Ã© obrigatÃ³ria.',
            'deposit_date.before_or_equal' => 'A data nÃ£o pode ser no futuro.',
        ]);

        $depositAmount = (float) $this->amount;
        $availableBalance = $this->getAvailableBalance();

        if ($depositAmount > $availableBalance) {
            $this->addError('amount', 'Saldo insuficiente. Saldo disponÃ­vel: R$ ' . number_format($availableBalance, 2, ',', '.'));
            return;
        }

        // Criar depÃ³sito
        $deposit = $this->goal->deposits()->create([
            'amount' => $this->amount,
            'description' => $this->description,
            'deposit_date' => $this->deposit_date,
        ]);

        // Criar transaÃ§Ã£o automÃ¡tica de despesa para manter o histÃ³rico
        $user = Auth::user();
        $savingsCategory = $user->categories()
            ->where('name', 'Economia')
            ->where('type', 'expense')
            ->first();

        if (!$savingsCategory) {
            $savingsCategory = $user->categories()->create([
                'name' => 'Economia',
                'type' => 'expense',
                'description' => 'DepÃ³sitos em metas de economia',
            ]);
        }

        // Verificar se jÃ¡ existe uma transaÃ§Ã£o para este depÃ³sito (evitar duplicatas)
        $existingTransaction = $user->transactions()
            ->get()
            ->filter(function ($transaction) use ($deposit) {
                $metadata = $transaction->metadata ?? [];
                return isset($metadata['savings_goal_deposit_id']) && $metadata['savings_goal_deposit_id'] == $deposit->id;
            })
            ->first();

        if (!$existingTransaction) {
            $user->transactions()->create([
                'category_id' => $savingsCategory->id,
                'type' => 'expense',
                'amount' => $this->amount,
                'description' => 'DepÃ³sito em meta: ' . $this->goal->name . ($this->description ? ' - ' . $this->description : ''),
                'date' => $this->deposit_date,
                'metadata' => [
                    'savings_goal_id' => $this->goal->id,
                    'savings_goal_deposit_id' => $deposit->id,
                ],
            ]);
        }

        session()->flash('message', 'DepÃ³sito realizado com sucesso!');
        
        $this->redirect(route('savings-goals.index'), navigate: true);
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Fazer DepÃ³sito</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Meta: {{ $goal->name }}</p>
        </div>
        <flux:button href="{{ route('savings-goals.index') }}" wire:navigate variant="ghost">
            Voltar
        </flux:button>
    </div>

    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
        <div class="mb-6 p-4 bg-zinc-50 dark:bg-zinc-800 rounded-lg">
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">Valor Atual</p>
                    <p class="text-lg font-semibold">R$ {{ number_format($goal->current_amount, 2, ',', '.') }}</p>
                </div>
                <div>
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">Meta</p>
                    <p class="text-lg font-semibold">R$ {{ number_format($goal->target_amount, 2, ',', '.') }}</p>
                </div>
                <div>
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">Saldo DisponÃ­vel</p>
                    <p class="text-lg font-semibold {{ $this->getAvailableBalance() < 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                        R$ {{ number_format($this->getAvailableBalance(), 2, ',', '.') }}
                    </p>
                </div>
            </div>
            <div class="mt-3">
                <div class="w-full bg-zinc-200 dark:bg-zinc-700 rounded-full h-2">
                    <div 
                        class="h-2 rounded-full transition-all bg-blue-500"
                        style="width: {{ min(100, $goal->progress_percentage) }}%"
                    ></div>
                </div>
                <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">{{ number_format($goal->progress_percentage, 1) }}% concluÃ­do</p>
            </div>
        </div>

        <form wire:submit="save" class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <flux:field>
                    <flux:label>Valor do DepÃ³sito</flux:label>
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
                    <flux:label>Data do DepÃ³sito</flux:label>
                    <flux:input type="date" wire:model="deposit_date" max="{{ now()->format('Y-m-d') }}" required />
                    <flux:error name="deposit_date" />
                </flux:field>

                <flux:field class="md:col-span-2">
                    <flux:label>DescriÃ§Ã£o (opcional)</flux:label>
                    <flux:textarea 
                        wire:model="description" 
                        placeholder="Ex: DepÃ³sito inicial, Economia do mÃªs..."
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
                    Fazer DepÃ³sito
                </flux:button>
            </div>
        </form>
    </div>
</div>
