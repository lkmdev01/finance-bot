<?php

namespace App\Services;

use App\Models\AgendaDigestPreference;
use App\Models\User;
use Illuminate\Support\Str;

class AgendaDigestPreferenceService
{
    public function handles(string $message): bool
    {
        $message = Str::of($message)->ascii()->lower()->squish()->toString();

        return preg_match('/\b(resumo|agenda)\b/u', $message) === 1
            && preg_match('/\b(diario|diaria|todo dia|todos os dias|horario do resumo|resumo diario)\b/u', $message) === 1;
    }

    public function handle(string $message, User $user): string
    {
        $timezone = (string) config('app.timezone', 'America/Sao_Paulo');
        if (! in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $timezone = 'America/Sao_Paulo';
        }

        $preference = AgendaDigestPreference::query()->firstOrNew(['user_id' => $user->id]);
        $normalized = Str::of($message)->ascii()->lower()->squish()->toString();

        if (preg_match('/\b(desative|desativar|pare|parar|cancele|cancelar|nao quero mais)\b/u', $normalized) === 1) {
            if ($preference->exists) {
                $preference->enabled = false;
                $preference->save();
            }

            return 'Tudo bem. Desativei o resumo diário da agenda.';
        }

        if (preg_match('/\b(status|qual horario|que horario)\b/u', $normalized) === 1) {
            if (! $preference->exists || ! $preference->enabled) {
                return 'O resumo diário da agenda está desativado. Para ativar, diga “me envie um resumo da agenda todo dia às 08:00”.';
            }

            return 'O resumo diário da agenda está ativado para '.$preference->send_time.' ('.$preference->timezone.').';
        }

        if (! $user->googleCalendarConnection || $user->googleCalendarConnection->revoked_at) {
            return 'Para ativar o resumo diário, primeiro conecte o Google Calendar em: '.route('integrations.google-calendar');
        }

        $requiredScopes = (array) config('services.google_calendar.scopes', []);
        if (array_diff($requiredScopes, (array) $user->googleCalendarConnection->scopes) !== []) {
            return 'A conexão Google precisa das permissões de agenda para ativar o resumo diário. Atualize em: '.route('integrations.google-calendar');
        }

        $sendTime = $preference->exists ? substr((string) $preference->send_time, 0, 5) : '08:00';
        if (preg_match('/\b(?:as|às)\s*([01]?\d|2[0-3])(?::([0-5]\d))?\b/u', $normalized, $matches) === 1) {
            $sendTime = str_pad($matches[1], 2, '0', STR_PAD_LEFT).':'.str_pad($matches[2] ?? '00', 2, '0', STR_PAD_LEFT);
        }

        $preference->fill([
            'enabled' => true,
            'send_time' => $sendTime.':00',
            'timezone' => $timezone,
        ])->save();

        return 'Ativei o resumo diário da agenda para '.$sendTime.' ('.$timezone.'). Você pode pedir “status do resumo diário” ou “desative o resumo diário” quando quiser.';
    }
}
