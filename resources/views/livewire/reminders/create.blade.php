<?php

use App\Models\Reminder;
use App\Services\WhatsApp\ReminderMessageTemplateFactory;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component {
    public string $title = '';
    public ?string $message = null;
    public string $frequency = 'once';
    public string $timezone = '';

    public ?string $once_date = null;
    public ?string $trigger_time = '09:00';
    public ?int $day_of_week = null;
    public ?int $day_of_month = null;
    public ?int $month_of_year = null;

    public bool $is_active = true;

    public function mount(): void
    {
        $this->timezone = (string) config('app.timezone', 'America/Sao_Paulo');
        $this->once_date = now()->toDateString();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'min:2', 'max:120'],
            'message' => ['nullable', 'string', 'max:2000'],
            'frequency' => ['required', 'in:once,daily,weekly,monthly,yearly'],
            'timezone' => ['required', 'string', 'max:80'],
            'once_date' => ['nullable', 'date'],
            'trigger_time' => ['nullable', 'date_format:H:i'],
            'day_of_week' => ['nullable', 'integer', 'min:0', 'max:6'],
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
            'month_of_year' => ['nullable', 'integer', 'min:1', 'max:12'],
            'is_active' => ['boolean'],
        ]);

        $this->validateScheduleRules($validated);

        $time = ($this->trigger_time ?: '09:00').':00';
        $next = $this->computeNextTriggerAt($validated['frequency'], $validated, $time);

        $finalMessage = trim((string) ($validated['message'] ?? ''));
        if ($finalMessage === '') {
            $type = ReminderMessageTemplateFactory::detect($validated['title'], '');
            $finalMessage = ReminderMessageTemplateFactory::buildFriendlyMessage($validated['title'], $validated['frequency'], $type, auth()->user()->name ?? null);
        }

        auth()->user()->reminders()->create([
            'title' => $validated['title'],
            'message' => $finalMessage,
            'frequency' => $validated['frequency'],
            'timezone' => $validated['timezone'],
            'next_trigger_at' => $next,
            'trigger_time' => $time,
            'day_of_week' => $validated['frequency'] === 'weekly' ? ($validated['day_of_week'] ?? null) : null,
            'day_of_month' => in_array($validated['frequency'], ['monthly', 'yearly'], true) ? ($validated['day_of_month'] ?? null) : null,
            'month_of_year' => $validated['frequency'] === 'yearly' ? ($validated['month_of_year'] ?? null) : null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'metadata' => [
                'source' => 'dashboard',
            ],
        ]);

        session()->flash('message', 'Lembrete criado com sucesso.');
        $this->redirect(route('reminders.index'), navigate: true);
    }

    private function validateScheduleRules(array $validated): void
    {
        $messages = [];

        if ($validated['frequency'] === 'once' && empty($validated['once_date'])) {
            $messages['once_date'] = 'Informe a data do lembrete.';
        }

        if ($validated['frequency'] === 'weekly' && $validated['day_of_week'] === null) {
            $messages['day_of_week'] = 'Informe o dia da semana.';
        }

        if ($validated['frequency'] === 'monthly' && $validated['day_of_month'] === null) {
            $messages['day_of_month'] = 'Informe o dia do mes.';
        }

        if ($validated['frequency'] === 'yearly') {
            if ($validated['day_of_month'] === null) {
                $messages['day_of_month'] = 'Informe o dia do mes.';
            }
            if ($validated['month_of_year'] === null) {
                $messages['month_of_year'] = 'Informe o mes.';
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    private function computeNextTriggerAt(string $frequency, array $validated, string $time): ?Carbon
    {
        $tz = $validated['timezone'] ?? config('app.timezone');

        if ($frequency === 'once') {
            $date = Carbon::parse((string) $validated['once_date'], $tz)->startOfDay();
            [$h, $m] = array_pad(explode(':', substr($time, 0, 5)), 2, 0);
            return $date->setTime((int) $h, (int) $m, 0);
        }

        $now = now($tz);
        [$h, $m] = array_pad(explode(':', substr($time, 0, 5)), 2, 0);

        if ($frequency === 'daily') {
            $next = $now->copy()->setTime((int) $h, (int) $m, 0);
            if ($next->lessThanOrEqualTo($now)) {
                $next->addDay();
            }
            return $next;
        }

        if ($frequency === 'weekly' && $validated['day_of_week'] !== null) {
            $next = $now->copy()->setTime((int) $h, (int) $m, 0);
            while ($next->dayOfWeek !== (int) $validated['day_of_week'] || $next->lessThanOrEqualTo($now)) {
                $next->addDay();
            }
            return $next;
        }

        if ($frequency === 'monthly' && $validated['day_of_month'] !== null) {
            $next = $now->copy()->startOfMonth()->day((int) $validated['day_of_month'])->setTime((int) $h, (int) $m, 0);
            if ($next->lessThanOrEqualTo($now)) {
                $next = $next->addMonthNoOverflow()->startOfMonth()->day(min((int) $validated['day_of_month'], $next->daysInMonth))->setTime((int) $h, (int) $m, 0);
            }
            return $next;
        }

        if ($frequency === 'yearly' && $validated['day_of_month'] !== null && $validated['month_of_year'] !== null) {
            $next = Carbon::create((int) $now->year, (int) $validated['month_of_year'], 1, (int) $h, (int) $m, 0, $tz);
            $next->day(min((int) $validated['day_of_month'], $next->daysInMonth));
            if ($next->lessThanOrEqualTo($now)) {
                $next->addYear();
                $next->day(min((int) $validated['day_of_month'], $next->daysInMonth));
            }
            return $next;
        }

        return null;
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Novo lembrete</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Crie lembretes pontuais ou recorrentes para receber no WhatsApp.</p>
        </div>
        <flux:button href="{{ route('reminders.index') }}" wire:navigate variant="ghost">Voltar</flux:button>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <form wire:submit="save" class="space-y-6">
            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <flux:input wire:model="title" label="Titulo" placeholder="Ex: Pagar academia" />
                <flux:select wire:model="frequency" label="Frequencia">
                    <flux:select.option value="once">Pontual</flux:select.option>
                    <flux:select.option value="daily">Diario</flux:select.option>
                    <flux:select.option value="weekly">Semanal</flux:select.option>
                    <flux:select.option value="monthly">Mensal</flux:select.option>
                    <flux:select.option value="yearly">Anual</flux:select.option>
                </flux:select>
                <flux:input wire:model="trigger_time" type="time" label="Horario" />
                <flux:input wire:model="once_date" type="date" label="Data (pontual)" />

                <flux:select wire:model="day_of_week" label="Dia da semana (semanal)">
                    <flux:select.option value="">Selecione</flux:select.option>
                    <flux:select.option value="0">Domingo</flux:select.option>
                    <flux:select.option value="1">Segunda</flux:select.option>
                    <flux:select.option value="2">Terca</flux:select.option>
                    <flux:select.option value="3">Quarta</flux:select.option>
                    <flux:select.option value="4">Quinta</flux:select.option>
                    <flux:select.option value="5">Sexta</flux:select.option>
                    <flux:select.option value="6">Sabado</flux:select.option>
                </flux:select>
                <flux:input wire:model="day_of_month" type="number" min="1" max="31" label="Dia do mes (mensal/anual)" />
                <flux:input wire:model="month_of_year" type="number" min="1" max="12" label="Mes (anual)" />
            </div>

            <div>
                <flux:textarea wire:model="message" label="Mensagem (opcional)" rows="4" placeholder="Se vazio, eu gero uma mensagem amigavel automaticamente." />
            </div>

            <flux:checkbox wire:model="is_active" label="Lembrete ativo" />

            <div class="flex justify-end gap-3">
                <flux:button href="{{ route('reminders.index') }}" wire:navigate variant="ghost">Cancelar</flux:button>
                <flux:button type="submit" variant="primary">Salvar lembrete</flux:button>
            </div>
        </form>
    </div>
</div>
