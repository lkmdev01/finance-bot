<?php

use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Services\GoogleCalendarOAuthService;
use App\Services\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'services.google_calendar.client_id' => 'test-client',
        'services.google_calendar.client_secret' => 'test-secret',
    ]);
});

function connectedCalendarUser(): User
{
    $user = User::factory()->create();
    GoogleCalendarConnection::create([
        'user_id' => $user->id,
        'google_email' => 'calendar@example.test',
        'refresh_token' => Crypt::encryptString('refresh-token-for-test'),
        'scopes' => ['https://www.googleapis.com/auth/calendar.events.owned'],
        'calendar_id' => 'primary',
        'connected_at' => now(),
    ]);

    return $user;
}

it('renews and caches the user-specific Google access token without logging the token', function (): void {
    $user = connectedCalendarUser();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token-test', 'expires_in' => 3600]),
    ]);

    $oauth = app(GoogleCalendarOAuthService::class);

    expect($oauth->getAccessToken($user))->toBe('access-token-test')
        ->and($oauth->getAccessToken($user))->toBe('access-token-test');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'oauth2.googleapis.com/token')
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'refresh-token-for-test');
});

it('lists only events in the requested time window from the connected primary calendar', function (): void {
    $user = connectedCalendarUser();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token-test', 'expires_in' => 3600]),
        'www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response(['items' => [['id' => 'event-1', 'summary' => 'Dentista']]]),
    ]);

    $events = app(GoogleCalendarService::class)->listEvents(
        $user,
        now()->startOfDay(),
        now()->endOfDay(),
    );

    expect($events)->toHaveCount(1)->and($events[0]['id'])->toBe('event-1');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/calendars/primary/events')
        && $request->hasHeader('Authorization', 'Bearer access-token-test')
        && str_contains((string) parse_url($request->url(), PHP_URL_QUERY), 'timeMin=')
        && str_contains((string) parse_url($request->url(), PHP_URL_QUERY), 'timeMax='));
});

it('checks freebusy availability using only the configured primary calendar', function (): void {
    $user = connectedCalendarUser();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token-test', 'expires_in' => 3600]),
        'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
            'calendars' => ['primary' => ['busy' => [['start' => '2026-11-10T10:00:00-03:00', 'end' => '2026-11-10T11:00:00-03:00']]]],
        ]),
    ]);

    $busy = app(GoogleCalendarService::class)->hasConflict(
        $user,
        now()->addDay()->setTime(10, 0),
        now()->addDay()->setTime(11, 0),
        'America/Sao_Paulo',
    );

    expect($busy)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/calendar/v3/freeBusy')
        && data_get($request->data(), 'items.0.id') === 'primary'
        && data_get($request->data(), 'timeZone') === 'America/Sao_Paulo');
});

it('creates Meet conferences only when explicitly requested and uses a stable idempotent event id', function (): void {
    $user = connectedCalendarUser();
    $created = [];
    Http::fake(function (Request $request) use (&$created) {
        if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
            return Http::response(['access_token' => 'access-token-test', 'expires_in' => 3600]);
        }
        if ($request->method() === 'POST') {
            $created[] = $request->data();
            if (count($created) > 1) {
                return Http::response(['error' => ['message' => 'Already exists']], 409);
            }

            return Http::response(['id' => $request['id'], 'htmlLink' => 'https://calendar.google.test/event'], 200);
        }

        if ($request->method() === 'GET') {
            return Http::response(['id' => basename((string) parse_url($request->url(), PHP_URL_PATH)), 'htmlLink' => 'https://calendar.google.test/event'], 200);
        }

        return Http::response([], 500);
    });

    $service = app(GoogleCalendarService::class);
    $payload = [
        'summary' => 'Consulta',
        'start' => ['dateTime' => now()->addDay()->toIso8601String()],
        'end' => ['dateTime' => now()->addDay()->addHour()->toIso8601String()],
    ];
    $first = $service->createEvent($user, $payload, 'whatsapp-message-123', createMeetLink: true);
    $second = $service->createEvent($user, $payload, 'whatsapp-message-123', createMeetLink: true);

    expect($first['id'])->toBe($second['id'])
        ->and($created)->toHaveCount(2)
        ->and($created[0]['conferenceData']['createRequest']['conferenceSolutionKey']['type'])->toBe('hangoutsMeet')
        ->and($created[0]['conferenceData']['createRequest']['requestId'])->toBe($created[1]['conferenceData']['createRequest']['requestId']);
});

it('does not create a Meet link for ordinary calendar events', function (): void {
    $user = connectedCalendarUser();
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
            return Http::response(['access_token' => 'access-token-test', 'expires_in' => 3600]);
        }

        return Http::response(['id' => $request['id']], 200);
    });

    app(GoogleCalendarService::class)->createEvent($user, [
        'summary' => 'Dentista',
        'start' => ['dateTime' => now()->addDay()->toIso8601String()],
        'end' => ['dateTime' => now()->addDay()->addHour()->toIso8601String()],
    ], 'message-ordinary-event');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && ! isset($request['conferenceData'])
        && str_contains($request->url(), 'conferenceDataVersion=0'));
});

it('marks a connection revoked after Google rejects its refresh token', function (): void {
    $user = connectedCalendarUser();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    expect(fn () => app(GoogleCalendarOAuthService::class)->getAccessToken($user))
        ->toThrow(RuntimeException::class);

    expect($user->fresh()->googleCalendarConnection->revoked_at)->not->toBeNull();
});
