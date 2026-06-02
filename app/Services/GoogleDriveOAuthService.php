<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GoogleDriveOAuthService
{
    public function clearCachedToken(User $user): void
    {
        Cache::forget($this->cacheKey($user));
    }

    public function getAccessToken(User $user, bool $forceRefresh = false): string
    {
        $cacheKey = $this->cacheKey($user);

        if (! $forceRefresh) {
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $connection = $user->googleDriveConnection;
        if (! $connection || $connection->revoked_at) {
            throw new RuntimeException('Google Drive nao conectado.');
        }

        $refreshToken = Crypt::decryptString((string) $connection->refresh_token);

        $response = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'client_id' => (string) config('services.google_drive.client_id'),
            'client_secret' => (string) config('services.google_drive.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            Log::warning('Falha ao renovar token do Google Drive', [
                'user_id' => $user->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Falha ao autenticar no Google Drive.');
        }

        $data = $response->json();
        $token = (string) ($data['access_token'] ?? '');
        $expiresIn = (int) ($data['expires_in'] ?? 3600);

        if ($token === '') {
            throw new RuntimeException('Token do Google Drive invalido.');
        }

        // Cache slightly less than expiry.
        $ttl = max(60, $expiresIn - 120);
        Cache::put($cacheKey, $token, now()->addSeconds($ttl));

        return $token;
    }

    private function cacheKey(User $user): string
    {
        return 'google_drive_access_token_user_'.$user->id;
    }
}
