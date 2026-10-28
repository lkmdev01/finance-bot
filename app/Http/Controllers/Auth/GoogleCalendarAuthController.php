<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\GoogleCalendarConnection;
use App\Services\GoogleCalendarOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleCalendarAuthController extends Controller
{
    public function __construct(private readonly GoogleCalendarOAuthService $oauth) {}

    public function redirect(): RedirectResponse
    {
        $connection = Auth::user()?->googleCalendarConnection;
        $scopes = array_values(array_unique(array_merge(
            (array) config('services.google_calendar.scopes', []),
            (array) config('services.google_tasks.scopes', []),
        )));
        $parameters = [
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
        ];
        $missingScopes = array_diff(
            $scopes,
            (array) ($connection?->scopes ?? []),
        );
        if ($connection?->revoked_at || $missingScopes !== []) {
            $parameters['prompt'] = 'consent';
        }

        return Socialite::driver('google')
            ->scopes($scopes)
            ->with($parameters)
            ->redirectUrl((string) config('services.google_calendar.redirect'))
            ->redirect();
    }

    public function callback(): RedirectResponse
    {
        $user = Auth::user();
        if (! $user) {
            return redirect()->route('login');
        }

        try {
            $googleUser = Socialite::driver('google')
                ->redirectUrl((string) config('services.google_calendar.redirect'))
                ->user();
        } catch (Throwable $exception) {
            Log::warning('Falha no retorno OAuth do Google Calendar', [
                'user_id' => $user->id,
                'exception' => $exception::class,
            ]);

            return redirect()->route('integrations.google-calendar')
                ->with('message', 'Nao foi possivel conectar o Google Calendar. Tente novamente.');
        }

        $existing = $user->googleCalendarConnection;
        $refreshToken = (string) ($googleUser->refreshToken ?? '');
        if ($refreshToken === '' && (! $existing || $existing->revoked_at)) {
            return redirect()->route('integrations.google-calendar')
                ->with('message', 'O Google nao concedeu acesso offline. Reconecte e aceite a permissao solicitada.');
        }

        $attributes = [
            'google_email' => $googleUser->getEmail() ?: $existing?->google_email,
            'scopes' => $scopes,
            'calendar_id' => $existing?->calendar_id ?: 'primary',
            'connected_at' => now(),
            'revoked_at' => null,
        ];
        if ($refreshToken !== '') {
            $attributes['refresh_token'] = Crypt::encryptString($refreshToken);
        }

        GoogleCalendarConnection::updateOrCreate(['user_id' => $user->id], $attributes);

        $this->oauth->clearCachedToken($user);

        return redirect()->route('integrations.google-calendar')
            ->with('message', 'Google Calendar conectado com sucesso.');
    }
}
