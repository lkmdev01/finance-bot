<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Services\GoogleCalendarOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class GoogleCalendarController extends Controller
{
    public function index(): View
    {
        $connection = Auth::user()?->googleCalendarConnection;
        $missingScopes = array_diff(
            array_merge(
                (array) config('services.google_calendar.scopes', []),
                (array) config('services.google_tasks.scopes', []),
            ),
            (array) ($connection?->scopes ?? []),
        );

        return view('pages.integrations.google-calendar', [
            'connection' => $connection,
            'needsAdditionalScopes' => $missingScopes !== [],
        ]);
    }

    public function disconnect(GoogleCalendarOAuthService $oauth): RedirectResponse
    {
        $user = Auth::user();
        $connection = $user?->googleCalendarConnection;
        if ($connection) {
            $connection->forceFill(['revoked_at' => now()])->save();
            $oauth->clearCachedToken($user);
        }

        return redirect()->route('integrations.google-calendar')
            ->with('message', 'O InovaFinance deixou de acessar este Google Calendar.');
    }
}
