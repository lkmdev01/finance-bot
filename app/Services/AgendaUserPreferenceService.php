<?php

namespace App\Services;

use App\Models\AgendaUserPreference;
use App\Models\User;
use Illuminate\Support\Str;

class AgendaUserPreferenceService
{
    public function handles(string $message): bool
    {
        $message = Str::of($message)->ascii()->lower()->squish()->toString();

        return preg_match('/\b(preferencias? da agenda|configurar agenda|duracao padrao|lembrete padrao|horario de trabalho)\b/u', $message) === 1;
    }

    public function handle(string $message, User $user): string
    {
        $message = Str::of($message)->ascii()->lower()->squish()->toString();
        $preference = AgendaUserPreference::query()->firstOrNew(['user_id' => $user->id]);

        if (preg_match('/\b(remova|remover|limpe|limpar|desative|desativar)\b.*\b(duracao padrao|lembrete padrao|horario de trabalho)\b/u', $message, $matches) === 1) {
            match ($matches[2]) {
                'duracao padrao' => $preference->default_event_duration_minutes = null,
                'lembrete padrao' => $preference->default_reminder_minutes = null,
                default => [$preference->workday_start, $preference->workday_end] = [null, null],
            };
            $preference->save();

            return 'Removi essa preferência da agenda.';
        }

        if (preg_match('/\b(duracao padrao)\b.*?\b(\d{1,3})\s*(minutos?|min)\b/u', $message, $matches) === 1) {
            $minutes = (int) $matches[2];
            if ($minutes < 5 || $minutes > 480) {
                return 'A duração padrão deve ficar entre 5 minutos e 8 horas.';
            }
            $preference->default_event_duration_minutes = $minutes;
            $preference->save();

            return "Salvei {$minutes} minutos como duração padrão dos novos eventos. Vou mostrar essa duração antes de pedir confirmação.";
        }

        if (preg_match('/\b(lembrete padrao)\b.*?\b(\d{1,5})\s*(minutos?|min)\b/u', $message, $matches) === 1) {
            $minutes = (int) $matches[2];
            if ($minutes > 40320) {
                return 'A antecedência do lembrete pode ser de até 40320 minutos.';
            }
            $preference->default_reminder_minutes = $minutes;
            $preference->save();

            return $minutes === 0
                ? 'Salvei o lembrete padrão para o horário do evento.'
                : "Salvei o lembrete padrão para {$minutes} minutos antes dos novos eventos.";
        }

        if (preg_match('/\bhorario de trabalho\b.*?\b([01]?\d|2[0-3])(?::([0-5]\d))?\s*(?:as|ate|-)\s*([01]?\d|2[0-3])(?::([0-5]\d))?\b/u', $message, $matches) === 1) {
            $start = sprintf('%02d:%02d:00', (int) $matches[1], (int) ($matches[2] ?? 0));
            $end = sprintf('%02d:%02d:00', (int) $matches[3], (int) ($matches[4] ?? 0));
            if ($start >= $end) {
                return 'O fim do horário de trabalho precisa ser posterior ao início no mesmo dia.';
            }
            $preference->workday_start = $start;
            $preference->workday_end = $end;
            $preference->save();

            return "Salvei sua janela preferida de trabalho: {$start}–{$end}. Vou usá-la ao sugerir horários; ela não bloqueia eventos fora desse período.";
        }

        $duration = $preference->default_event_duration_minutes
            ? $preference->default_event_duration_minutes.' min'
            : 'não definida';
        $reminder = $preference->default_reminder_minutes !== null
            ? $preference->default_reminder_minutes.' min antes'
            : 'não definido';
        $hours = $preference->workday_start && $preference->workday_end
            ? substr($preference->workday_start, 0, 5).'–'.substr($preference->workday_end, 0, 5)
            : 'não definido';

        return "Preferências da agenda:\nDuração padrão: {$duration}\nLembrete padrão: {$reminder}\nJanela preferida: {$hours}\nPara alterar, diga “duração padrão 30 minutos”, “lembrete padrão 10 minutos antes” ou “horário de trabalho das 9 às 17”.";
    }

    public function forUser(User $user): AgendaUserPreference
    {
        return AgendaUserPreference::query()->firstOrNew(['user_id' => $user->id]);
    }
}
