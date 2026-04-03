<?php

use App\Models\SavingsGoal;
use App\Models\SavingsGoalAlert;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public ?SavingsGoal $goal = null;
    public string $type = 'milestone';
    public ?string $thresholdPercentage = null;
    public ?string $daysBeforeDeadline = null;

    public function mount(?SavingsGoal $goal = null): void
    {
        if (! $goal) {
            $goal = Auth::user()->savingsGoals()->findOrFail(request()->route('savingsGoal'));
        }
        $this->goal = $goal;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'type' => ['required', 'string', 'in:milestone,deadline,low_progress'],
            'thresholdPercentage' => ['required_if:type,milestone', 'nullable', 'numeric', 'min:1', 'max:100'],
            'daysBeforeDeadline' => ['required_if:type,deadline', 'nullable', 'integer', 'min:1'],
        ], [
            'type.required' => 'O tipo de alerta Ã© obrigatÃ³rio.',
            'thresholdPercentage.required_if' => 'A porcentagem Ã© obrigatÃ³ria para alertas de marco.',
            'thresholdPercentage.numeric' => 'A porcentagem deve ser um nÃºmero.',
            'thresholdPercentage.min' => 'A porcentagem deve ser pelo menos 1%.',
            'thresholdPercentage.max' => 'A porcentagem nÃ£o pode ser maior que 100%.',
            'daysBeforeDeadline.required_if' => 'Os dias antes do prazo sÃ£o obrigatÃ³rios para alertas de prazo.',
            'daysBeforeDeadline.integer' => 'Os dias devem ser um nÃºmero inteiro.',
            'daysBeforeDeadline.min' => 'Os dias devem ser pelo menos 1.',
        ]);

        Auth::user()->savingsGoalAlerts()->create([
            'savings_goal_id' => $this->goal->id,
            'type' => $this->type,
            'threshold_percentage' => $this->thresholdPercentage ? (float) $this->thresholdPercentage : null,
            'days_before_deadline' => $this->daysBeforeDeadline ? (int) $this->daysBeforeDeadline : null,
        ]);

        session()->flash('message', 'Alerta criado com sucesso!');
        $this->redirect(route('savings-goals.index'), navigate: true);
    }

    public function delete(int $alertId): void
    {
        $alert = Auth::user()->savingsGoalAlerts()->findOrFail($alertId);
        $alert->delete();
        session()->flash('message', 'Alerta excluÃ­do com sucesso!');
    }

    public function toggleActive(int $alertId): void
    {
        $alert = Auth::user()->savingsGoalAlerts()->findOrFail($alertId);
        $alert->update(['is_active' => ! $alert->is_active]);
        session()->flash('message', 'Status do alerta atualizado!');
    }

    public function with(): array
    {
        return [
            'alerts' => $this->goal->alerts()->orderBy('created_at', 'desc')->get(),
        ];
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Alertas da Meta: {{ $goal->name }}</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Configure alertas para esta meta de economia</p>
        </div>
        <flux:button href="{{ route('savings-goals.index') }}" wire:navigate variant="ghost">
            Voltar
        </flux:button>
    </div>

    <!-- FormulÃ¡rio para criar novo alerta -->
    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
        <h2 class="text-lg font-semibold mb-4">Criar Novo Alerta</h2>
        <form wire:submit="save" class="space-y-6">
            <flux:field>
                <flux:label>Tipo de Alerta</flux:label>
                <flux:select wire:model.live="type" required>
                    <option value="milestone">Marco (porcentagem atingida)</option>
                    <option value="deadline">Prazo (dias antes do prazo final)</option>
                    <option value="low_progress">Progresso Baixo (abaixo do esperado)</option>
                </flux:select>
                <flux:error name="type" />
            </flux:field>

            @if($type === 'milestone')
                <flux:field>
                    <flux:label>Porcentagem do Marco (%)</flux:label>
                    <flux:input 
                        type="number" 
                        wire:model="thresholdPercentage" 
                        min="1" 
                        max="100" 
                        step="1"
                        placeholder="Ex: 50"
                        required
                    />
                    <flux:description>
                        O alerta serÃ¡ disparado quando a meta atingir esta porcentagem.
                    </flux:description>
                    <flux:error name="thresholdPercentage" />
                </flux:field>
            @endif

            @if($type === 'deadline')
                <flux:field>
                    <flux:label>Dias Antes do Prazo</flux:label>
                    <flux:input 
                        type="number" 
                        wire:model="daysBeforeDeadline" 
                        min="1"
                        placeholder="Ex: 7"
                        required
                    />
                    <flux:description>
                        O alerta serÃ¡ disparado quando faltarem este nÃºmero de dias para o prazo final.
                    </flux:description>
                    <flux:error name="daysBeforeDeadline" />
                </flux:field>
            @endif

            @if($type === 'low_progress')
                <flux:field>
                    <flux:description>
                        Este alerta serÃ¡ disparado automaticamente quando o progresso da meta estiver abaixo de 50% do esperado para a data atual.
                    </flux:description>
                </flux:field>
            @endif

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary">
                    Criar Alerta
                </flux:button>
            </div>
        </form>
    </div>

    <!-- Lista de alertas existentes -->
    @if($alerts->count() > 0)
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
            <h2 class="text-lg font-semibold mb-4">Alertas Configurados</h2>
            <div class="space-y-4">
                @foreach($alerts as $alert)
                    <div class="flex items-center justify-between p-4 border border-zinc-200 dark:border-zinc-700 rounded-lg">
                        <div class="flex-1">
                            <div class="flex items-center gap-3 mb-2">
                                <span class="px-2 py-1 text-xs font-medium rounded-full 
                                    {{ $alert->type === 'milestone' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' : '' }}
                                    {{ $alert->type === 'deadline' ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' : '' }}
                                    {{ $alert->type === 'low_progress' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' : '' }}
                                ">
                                    @if($alert->type === 'milestone')
                                        Marco
                                    @elseif($alert->type === 'deadline')
                                        Prazo
                                    @else
                                        Progresso Baixo
                                    @endif
                                </span>
                                @if($alert->is_active)
                                    <span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                        Ativo
                                    </span>
                                @else
                                    <span class="px-2 py-1 text-xs font-medium rounded-full bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200">
                                        Inativo
                                    </span>
                                @endif
                            </div>
                            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                                @if($alert->type === 'milestone')
                                    Dispara quando atingir {{ $alert->threshold_percentage }}% da meta
                                @elseif($alert->type === 'deadline')
                                    Dispara {{ $alert->days_before_deadline }} dias antes do prazo final
                                @else
                                    Dispara quando o progresso estiver abaixo do esperado
                                @endif
                            </p>
                            @if($alert->last_triggered_at)
                                <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">
                                    Ãšltimo disparo: {{ $alert->last_triggered_at->format('d/m/Y H:i') }}
                                </p>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            <flux:button 
                                wire:click="toggleActive({{ $alert->id }})"
                                variant="ghost"
                                size="sm"
                            >
                                {{ $alert->is_active ? 'Desativar' : 'Ativar' }}
                            </flux:button>
                            <flux:button 
                                wire:click="delete({{ $alert->id }})"
                                wire:confirm="Tem certeza que deseja excluir este alerta?"
                                variant="ghost"
                                size="sm"
                            >
                                Excluir
                            </flux:button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 text-center">
            <p class="text-zinc-500 dark:text-zinc-400">Nenhum alerta configurado para esta meta.</p>
        </div>
    @endif
</div>
