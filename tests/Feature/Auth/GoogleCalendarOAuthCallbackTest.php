<?php

use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Services\GoogleCalendarOAuthService;
use Illuminate\Support\Facades\Crypt;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('persists the scopes requested by the calendar oauth flow after callback', function (): void {
    $calendarScopes = [
        'https://www.googleapis.com/auth/calendar.events.owned',
        'https://www.googleapis.com/auth/calendar.events.freebusy',
    ];
    $taskScopes = ['https://www.googleapis.com/auth/tasks'];
    config([
        'services.google_calendar.scopes' => $calendarScopes,
        'services.google_tasks.scopes' => $taskScopes,
    ]);

    $user = User::factory()->create(['whatsapp_verified_at' => now()]);
    actingAs($user);

    $googleUser = (new SocialiteUser)->map([
        'id' => 'google-calendar-user-123',
        'name' => 'Calendar User',
        'email' => 'calendar-user@example.test',
    ])->setRefreshToken('test-refresh-token');
    Socialite::fake('google', $googleUser);

    $oauth = Mockery::mock(GoogleCalendarOAuthService::class);
    $oauth->shouldReceive('clearCachedToken')->once()->withArgs(fn (User $actual): bool => $actual->is($user));
    app()->instance(GoogleCalendarOAuthService::class, $oauth);

    get(route('google-calendar.callback'))
        ->assertRedirect(route('integrations.google-calendar'))
        ->assertSessionHas('message', 'Google Calendar conectado com sucesso.');

    $connection = GoogleCalendarConnection::query()->where('user_id', $user->id)->firstOrFail();

    expect($connection->scopes)->toBe(array_merge($calendarScopes, $taskScopes))
        ->and(Crypt::decryptString($connection->refresh_token))->toBe('test-refresh-token')
        ->and($connection->revoked_at)->toBeNull();
});
