<?php

namespace App\Services;

use App\Models\GoogleCalendarConnection;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleCalendarOAuthService
{
    public function clearCachedToken(User $user): void
    {
        Cache::forget($this->cacheKey($user));
    }

    public function getAccessToken(User $user, bool $forceRefresh = false): string
    {
        $connection = $user->googleCalendarConnection;
        if (! $connection || $connection->revoked_at) {
            throw new RuntimeException('Google Calendar nao conectado.');
        }

        $cacheKey = $this->cacheKey($user);
        if (! $forceRefresh) {
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $response = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'client_id' => (string) config('services.google_calendar.client_id'),
            'client_secret' => (string) config('services.google_calendar.client_secret'),
            'refresh_token' => Crypt::decryptString((string) $connection->refresh_token),
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            if (in_array($response->json('error'), ['invalid_grant', 'invalid_token'], true)) {
                $connection->forceFill(['revoked_at' => now()])->save();
            }

            throw new RuntimeException('Falha ao autenticar no Google Calendar. Reconecte a conta.');
        }

        $data = $response->json();
        $token = (string) ($data['access_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('Resposta de autenticacao do Google Calendar invalida.');
        }

        Cache::put($cacheKey, $token, now()->addSeconds(max(60, (int) ($data['expires_in'] ?? 3600) - 120)));

        return $token;
    }

    private function cacheKey(User $user): string
    {
        return 'google_calendar_access_token_user_'.$user->id;
    }
}
