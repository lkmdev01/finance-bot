<?php

use App\Models\Reminder;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public function delete(int $reminderId): void
    {
        $reminder = Auth::user()->reminders()->findOrFail($reminderId);
        $reminder->delete();

        session()->flash('message', 'Lembrete excluído com sucesso.');
    }

    public function toggleActive(int $reminderId): void
    {
        $reminder = Auth::user()->reminders()->findOrFail($reminderId);
        $reminder->update(['is_active' => ! $reminder->is_active]);

        session()->flash('message', 'Status do lembrete atualizado.');
    }

    public function with(): array
    {
        return [
            'reminders' => Auth::user()->reminders()
                ->orderBy('is_active', 'desc')
                ->orderByRaw('next_trigger_at is null')
                ->orderBy('next_trigger_at')
                ->orderBy('id', 'desc')
                ->get(),
        ];
    }

    public function frequencyLabel(Reminder $reminder): string
    {
        return match ($reminder->frequency) {
            'once' => 'Pontual',
            'daily' => 'Diário',
            'weekly' => 'Semanal',
            'monthly' => 'Mensal',
            'yearly' => 'Anual',
            default => ucfirst((string) $reminder->frequency),
        };
    }

    public function scheduleLabel(Reminder $reminder): string
    {
        if ($reminder->frequency === 'once') {
            return $reminder->next_trigger_at?->format('d/m/Y H:i') ?? 'Não agendado';
        }

        $time = $reminder->trigger_time ? substr((string) $reminder->trigger_time, 0, 5) : '09:00';

        if ($reminder->frequency === 'daily') {
            return "Todo dia as {$time}";
        }

        if ($reminder->frequency === 'weekly' && $reminder->day_of_week !== null) {
            $weekdays = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab'];
            $day = $weekdays[(int) $reminder->day_of_week] ?? 'Semana';
            return "{$day} as {$time}";
        }

        if ($reminder->frequency === 'monthly' && $reminder->day_of_month !== null) {
            return "Dia {$reminder->day_of_month} as {$time}";
        }

        if ($reminder->frequency === 'yearly' && $reminder->day_of_month !== null && $reminder->month_of_year !== null) {
            return sprintf('%02d/%02d as %s', (int) $reminder->day_of_month, (int) $reminder->month_of_year, $time);
        }

        return $reminder->next_trigger_at?->format('d/m/Y H:i') ?? 'Não agendado';
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Lembretes</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Gerencie lembretes pontuais e recorrentes enviados pelo WhatsApp.</p>
        </div>
        <flux:button href="{{ route('reminders.create') }}" wire:navigate variant="primary">
            Novo Lembrete
        </flux:button>
    </div>

    <div class="grid grid-cols-1 gap-4">
        @forelse($reminders as $reminder)
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 {{ ! $reminder->is_active ? 'opacity-60' : '' }}">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex-1">
                        <div class="flex items-center gap-3 mb-3">
                            <h2 class="text-lg font-semibold">{{ $reminder->title }}</h2>
                            <span class="px-2 py-1 text-xs rounded-full {{ $reminder->is_active ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200' }}">
                                {{ $reminder->is_active ? 'Ativo' : 'Inativo' }}
                            </span>
                            <span class="px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                {{ $this->frequencyLabel($reminder) }}
                            </span>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <p class="text-xs text-zinc-500">Agenda</p>
                                <p class="font-medium">{{ $this->scheduleLabel($reminder) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-zinc-500">Proximo disparo</p>
                                <p class="font-medium">{{ $reminder->next_trigger_at?->format('d/m/Y H:i') ?: 'Não definido' }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-zinc-500">Ultimo envio</p>
                                <p class="font-medium">{{ $reminder->last_sent_at?->format('d/m/Y H:i') ?: 'Nunca' }}</p>
                            </div>
                        </div>

                        @if(! blank($reminder->message))
                            <div class="mt-4">
                                <p class="text-xs text-zinc-500">Mensagem</p>
                                <p class="text-sm text-zinc-700 dark:text-zinc-300 line-clamp-2">{{ $reminder->message }}</p>
                            </div>
                        @endif
                    </div>

                    <div class="flex items-center gap-2">
                        <flux:button wire:click="toggleActive({{ $reminder->id }})" variant="ghost" size="sm" icon="{{ $reminder->is_active ? 'pause' : 'play' }}" />
                        <flux:button href="{{ route('reminders.edit', $reminder) }}" wire:navigate variant="ghost" size="sm" icon="pencil" />
                        <flux:button wire:click="delete({{ $reminder->id }})" wire:confirm="Deseja excluir este lembrete?" variant="ghost" size="sm" icon="trash" class="text-red-600 hover:text-red-700 dark:text-red-400" />
                    </div>
                </div>
            </div>
        @empty
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-12 text-center">
                <p class="text-zinc-500 dark:text-zinc-400 mb-4">Nenhum lembrete cadastrado.</p>
                <flux:button href="{{ route('reminders.create') }}" wire:navigate variant="primary">
                    Criar primeiro lembrete
                </flux:button>
            </div>
        @endforelse
    </div>
</div>
