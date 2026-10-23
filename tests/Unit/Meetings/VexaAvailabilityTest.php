<?php

uses(Tests\TestCase::class);

use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\Meetings\MeetingCommandService;
use App\Services\Meetings\MeetingUrlParser;
use App\Services\Meetings\VexaApiClient;
use Illuminate\Support\Facades\Http;

it('recognizes a reachable Vexa API with both required scopes', function () {
    config([
        'vexa.base_url' => 'http://100.64.0.10:18056',
        'vexa.api_key' => 'test-key',
    ]);
    Http::fake(['*/auth/me' => Http::response(['scopes' => ['bot', 'tx']], 200)]);

    expect((new VexaApiClient())->isAvailable())->toBeTrue();
});

it('rejects new meeting requests while the meetings module is disabled', function () {
    config(['vexa.enabled' => false]);

    Http::fake();
    $service = new MeetingCommandService(new MeetingUrlParser(), new VexaApiClient());

    $reply = $service->handle(
        'reunião https://meet.google.com/abc-defg-hij participantes avisados e autorizo',
        new User(['id' => 1]),
        new WhatsAppContact(['id' => 1, 'user_id' => 1]),
    );

    expect($reply)->toContain('indisponível');
    Http::assertNothingSent();
});

it('does not queue a new meeting when Vexa is unavailable', function () {
    config([
        'vexa.enabled' => true,
        'vexa.base_url' => 'http://100.64.0.10:18056',
        'vexa.api_key' => 'test-key',
    ]);
    Http::fake(['*/auth/me' => Http::response(['detail' => 'unavailable'], 503)]);

    $service = new MeetingCommandService(new MeetingUrlParser(), new VexaApiClient());
    $reply = $service->handle(
        'reunião https://meet.google.com/abc-defg-hij participantes avisados e autorizo',
        new User(['id' => 1]),
        new WhatsAppContact(['id' => 1, 'user_id' => 1]),
    );

    expect($reply)->toContain('temporariamente indisponível');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/auth/me')
        && $request->hasHeader('X-API-Key', 'test-key'));
});

it('requires the scopes used for bot requests and meeting status reads', function () {
    config([
        'vexa.enabled' => true,
        'vexa.base_url' => 'http://100.64.0.10:18056',
        'vexa.api_key' => 'test-key',
    ]);
    Http::fake(['*/auth/me' => Http::response(['scopes' => ['bot']], 200)]);

    expect((new VexaApiClient())->isAvailable())->toBeFalse();
});

it('requests recording without realtime transcription from Vexa', function () {
    config(['vexa.base_url' => 'https://vexa.test', 'vexa.api_key' => 'test-key']);
    Http::fake(['https://vexa.test/bots' => Http::response(['id' => 42, 'status' => 'requested'], 201)]);

    (new VexaApiClient())->sendBot('https://meet.google.com/abc-defg-hij');

    Http::assertSent(fn ($request) => $request->url() === 'https://vexa.test/bots'
        && $request['recording_enabled'] === true
        && $request['transcribe_enabled'] === false);
});
