<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\GoogleDriveConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleDriveAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        $scopes = (array) config('services.google_drive.scopes', []);

        return Socialite::driver('google')
            ->scopes($scopes)
            ->with([
                'access_type' => 'offline',
                'prompt' => 'consent',
            ])
            ->redirectUrl((string) config('services.google_drive.redirect'))
            ->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')
                ->redirectUrl((string) config('services.google_drive.redirect'))
                ->user();
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('integrations.google-drive')
                ->with('message', 'Nao foi possivel conectar o Google Drive. Tente novamente.');
        }

        $user = Auth::user();
        if (! $user) {
            return redirect()->route('login');
        }

        $refreshToken = (string) ($googleUser->refreshToken ?? '');
        if ($refreshToken === '') {
            // When Google does not return refresh token (already granted before), keep existing.
            $existing = $user->googleDriveConnection;
            if (! $existing) {
                return redirect()->route('integrations.google-drive')
                    ->with('message', 'Conexao recusada: nao recebi permissao offline. Tente novamente.');
            }

            $existing->forceFill([
                'connected_at' => now(),
                'revoked_at' => null,
            ])->save();

            return redirect()->route('integrations.google-drive')
                ->with('message', 'Google Drive reconectado com sucesso.');
        }

        GoogleDriveConnection::updateOrCreate(
            ['user_id' => $user->id],
            [
                'refresh_token' => Crypt::encryptString($refreshToken),
                'scopes' => (array) config('services.google_drive.scopes', []),
                'connected_at' => now(),
                'revoked_at' => null,
            ]
        );

        return redirect()->route('integrations.google-drive')
            ->with('message', 'Google Drive conectado com sucesso.');
    }
}

