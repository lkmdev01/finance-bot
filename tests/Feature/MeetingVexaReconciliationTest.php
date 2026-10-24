<?php

use App\Jobs\SendMeetingDurationWarning;
use App\Jobs\StopMeetingBot;
use App\Models\Meeting;
use App\Models\MeetingTranscriptionTask;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\Meetings\MeetingCommandService;
use App\Services\Meetings\MeetingUrlParser;
use App\Services\Meetings\VexaApiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function makeMeetingForVexaReconciliation(array $attributes = []): Meeting
{
    $phone = '+5511'.str_pad((string) random_int(100000000, 999999999), 9, '0', STR_PAD_LEFT);
    $user = User::factory()->create(['phone_number' => $phone]);

    return Meeting::query()->create(array_merge([
        'user_id' => $user->id,
        'platform' => 'teams',
        'native_meeting_id_hash' => hash('sha256', 'teams-meeting'),
        'native_meeting_id' => '19:meeting_example@thread.v2',
        'meeting_url' => 'https://teams.microsoft.com/l/meetup-join/19:meeting_example@thread.v2',
        'vexa_meeting_id' => '42',
        'vexa_meeting_id_hash' => hash_hmac('sha256', '42', (string) config('app.key')),
        'status' => 'active',
        'started_at' => now()->subHour(),
        'active_since' => now()->subHour(),
        'participant_notice_attested' => true,
        'participant_notice_attested_at' => now()->subHour(),
    ], $attributes));
}

beforeEach(function () {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'vexa.enabled' => true,
        'vexa.base_url' => 'https://vexa.test',
        'vexa.api_key' => 'test-api-key-not-real',
        'vexa.retention_days' => 90,
        'vexa.max_meeting_duration_minutes' => 240,
        'vexa.max_meeting_duration_warning_minutes' => 15,
        'vexa.stop_retry_seconds' => 180,
        'queue.default' => 'sync',
    ]);
    app()->forgetInstance('encrypter');
    Cache::flush();
    Queue::fake();
});

it('reconciles a Vexa completed state and creates one deferred transcription task idempotently', function () {
    $meeting = makeMeetingForVexaReconciliation();
    Http::fake(['https://vexa.test/meetings/42' => Http::response(['id' => 42, 'status' => 'completed'], 200)]);

    $this->artisan('meetings:reconcile-vexa')->assertSuccessful();
    $this->artisan('meetings:reconcile-vexa')->assertSuccessful();

    expect($meeting->fresh()->status)->toBe('awaiting_transcription')
        ->and($meeting->fresh()->completed_at)->not->toBeNull()
        ->and(MeetingTranscriptionTask::query()->where('meeting_id', $meeting->id)->count())->toBe(1);
});

it('recovers a locally failed meeting when Vexa confirms completion', function () {
    $meeting = makeMeetingForVexaReconciliation(['status' => 'failed', 'failure_code' => 'vexa_bot_request_failed']);
    Http::fake(['https://vexa.test/meetings/42' => Http::response(['id' => 42, 'status' => 'completed'], 200)]);

    $this->artisan('meetings:reconcile-vexa')->assertSuccessful();

    expect($meeting->fresh()->status)->toBe('awaiting_transcription')
        ->and(MeetingTranscriptionTask::query()->where('meeting_id', $meeting->id)->count())->toBe(1);
});

it('reconciles a status-change event that marked Laravel completed without the typed completed webhook', function () {
    $meeting = makeMeetingForVexaReconciliation(['status' => 'completed']);
    Http::fake(['https://vexa.test/meetings/42' => Http::response(['id' => 42, 'status' => 'completed'], 200)]);

    $this->artisan('meetings:reconcile-vexa')->assertSuccessful();

    expect($meeting->fresh()->status)->toBe('awaiting_transcription')
        ->and(MeetingTranscriptionTask::query()->where('meeting_id', $meeting->id)->count())->toBe(1);
});

it('preserves an in-progress transcription when Vexa still reports the meeting completed', function () {
    $meeting = makeMeetingForVexaReconciliation(['status' => 'transcribing']);
    $task = app(App\Services\Meetings\MeetingTranscriptionTaskService::class)->createFor($meeting);
    $task->update(['status' => 'processing']);
    Http::fake(['https://vexa.test/meetings/42' => Http::response(['id' => 42, 'status' => 'completed'], 200)]);

    $this->artisan('meetings:reconcile-vexa')->assertSuccessful();

    expect($meeting->fresh()->status)->toBe('transcribing')
        ->and($task->fresh()->status)->toBe('processing')
        ->and(MeetingTranscriptionTask::query()->where('meeting_id', $meeting->id)->count())->toBe(1);
});

it('does not treat a quiet active Teams bot as proof that participants left', function () {
    $meeting = makeMeetingForVexaReconciliation(['transcript_segments' => []]);
    Http::fake(['https://vexa.test/meetings/42' => Http::response(['id' => 42, 'status' => 'active'], 200)]);

    $this->artisan('meetings:reconcile-vexa')->assertSuccessful();

    expect($meeting->fresh()->status)->toBe('active')
        ->and($meeting->fresh()->stop_requested_at)->toBeNull();
    Queue::assertNotPushed(StopMeetingBot::class);
});

it('uses the Vexa start time as the active duration anchor when available', function () {
    $meeting = makeMeetingForVexaReconciliation(['active_since' => null]);
    $remoteStart = now()->subHour()->startOfSecond();
    Http::fake(['https://vexa.test/meetings/42' => Http::response([
        'id' => 42,
        'status' => 'active',
        'start_time' => $remoteStart->toIso8601String(),
    ], 200)]);

    $this->artisan('meetings:reconcile-vexa')->assertSuccessful();

    expect($meeting->fresh()->active_since->timestamp)->toBe($remoteStart->timestamp);
});

it('warns once and requests a controlled stop at the configured maximum duration', function () {
    $meeting = makeMeetingForVexaReconciliation(['active_since' => now()->subMinutes(240)]);
    Http::fake(['https://vexa.test/meetings/42' => Http::response(['id' => 42, 'status' => 'active'], 200)]);

    $this->artisan('meetings:reconcile-vexa')->assertSuccessful();
    $this->artisan('meetings:reconcile-vexa')->assertSuccessful();

    expect($meeting->fresh()->status)->toBe('stopping')
        ->and($meeting->fresh()->stop_reason)->toBe('max_duration')
        ->and($meeting->fresh()->stop_requested_at)->not->toBeNull()
        ->and($meeting->fresh()->max_duration_warning_sent_at)->not->toBeNull();
    expect(Queue::pushed(StopMeetingBot::class)->count())->toBe(1)
        ->and(Queue::pushed(SendMeetingDurationWarning::class)->count())->toBe(1);
});

it('lets a user stop exactly their own active meeting through WhatsApp', function () {
    $own = makeMeetingForVexaReconciliation();
    $other = makeMeetingForVexaReconciliation([
        'native_meeting_id' => 'other-thread',
        'native_meeting_id_hash' => hash('sha256', 'other'),
        'vexa_meeting_id' => '43',
        'vexa_meeting_id_hash' => hash_hmac('sha256', '43', (string) config('app.key')),
    ]);
    $contact = new WhatsAppContact;

    $reply = app(MeetingCommandService::class)->handle('encerrar reunião', $own->user, $contact);

    expect($reply)->toContain('aguardar a gravação ser finalizada')
        ->and($own->fresh()->status)->toBe('stopping')
        ->and($own->fresh()->stop_reason)->toBe('user')
        ->and($other->fresh()->status)->toBe('active');
    Queue::assertPushed(StopMeetingBot::class, fn (StopMeetingBot $job) => $job->meetingId === $own->id);
});

it('refuses an ambiguous stop command without stopping either active meeting', function () {
    $first = makeMeetingForVexaReconciliation();
    $second = makeMeetingForVexaReconciliation([
        'native_meeting_id' => 'other-thread',
        'native_meeting_id_hash' => hash('sha256', 'other'),
        'vexa_meeting_id' => '43',
        'vexa_meeting_id_hash' => hash_hmac('sha256', '43', (string) config('app.key')),
    ]);
    $second->forceFill(['user_id' => $first->user_id])->save();

    $reply = app(MeetingCommandService::class)->handle('encerrar reuniao', $first->user, new WhatsAppContact);

    expect($reply)->toContain('mais de uma reunião ativa')
        ->and($first->fresh()->status)->toBe('active');
    Queue::assertNotPushed(StopMeetingBot::class);
});

it('calls the official Vexa stop endpoint and waits for remote completion before creating transcription', function () {
    $meeting = makeMeetingForVexaReconciliation(['status' => 'stopping', 'stop_reason' => 'user', 'stop_requested_at' => now()]);
    $meetingStates = Http::sequence()
        ->push(['id' => 42, 'status' => 'stopping'], 200)
        ->push(['id' => 42, 'status' => 'completed'], 200);
    Http::fake([
        'https://vexa.test/bots/teams/19%3Ameeting_example%40thread.v2' => Http::response(['status' => 'stopping', 'meeting_id' => 42], 200),
        'https://vexa.test/meetings/42' => $meetingStates,
    ]);

    (new StopMeetingBot($meeting->id))->handle(app(VexaApiClient::class));

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/bots/teams/'));
    expect(MeetingTranscriptionTask::query()->where('meeting_id', $meeting->id)->count())->toBe(0);

    (new App\Jobs\PollMeetingStatus($meeting->id))->handle(app(VexaApiClient::class), app(App\Services\Meetings\MeetingTranscriptionTaskService::class));
    expect($meeting->fresh()->status)->toBe('stopping')
        ->and(MeetingTranscriptionTask::query()->where('meeting_id', $meeting->id)->count())->toBe(0);

    (new App\Jobs\PollMeetingStatus($meeting->id))->handle(app(VexaApiClient::class), app(App\Services\Meetings\MeetingTranscriptionTaskService::class));

    expect($meeting->fresh()->status)->toBe('awaiting_transcription')
        ->and(MeetingTranscriptionTask::query()->where('meeting_id', $meeting->id)->count())->toBe(1);
});

it('retries an old stopping request if Vexa still reports a live bot', function () {
    $meeting = makeMeetingForVexaReconciliation([
        'status' => 'stopping',
        'stop_reason' => 'user',
        'stop_requested_at' => now()->subMinutes(5),
    ]);
    Http::fake(['https://vexa.test/meetings/42' => Http::response(['id' => 42, 'status' => 'active'], 200)]);

    $this->artisan('meetings:reconcile-vexa')->assertSuccessful();

    expect($meeting->fresh()->status)->toBe('stopping')
        ->and($meeting->fresh()->stop_requested_at->timestamp)->toBe(now()->timestamp);
    Queue::assertPushed(StopMeetingBot::class, fn (StopMeetingBot $job) => $job->meetingId === $meeting->id);
});

it('does not mark an active meeting for review only because ordinary polling reached its old limit', function () {
    $meeting = makeMeetingForVexaReconciliation(['status' => 'active']);

    (new App\Jobs\PollMeetingStatus($meeting->id, 240))->handle(
        app(VexaApiClient::class),
        app(App\Services\Meetings\MeetingTranscriptionTaskService::class),
    );

    expect($meeting->fresh()->status)->toBe('active')
        ->and($meeting->fresh()->failure_code)->toBeNull();
    Queue::assertPushed(App\Jobs\PollMeetingStatus::class, fn (App\Jobs\PollMeetingStatus $job) => $job->pollCount === 241);
});

it('does not reconcile or stop meetings while Vexa is disabled', function () {
    $meeting = makeMeetingForVexaReconciliation();
    config(['vexa.enabled' => false]);
    Http::fake();

    $this->artisan('meetings:reconcile-vexa')->assertSuccessful();
    $reply = app(MeetingCommandService::class)->handle('encerrar reunião', $meeting->user, new WhatsAppContact);

    expect($reply)->toContain('indisponível')
        ->and($meeting->fresh()->status)->toBe('active');
    Http::assertNothingSent();
    Queue::assertNotPushed(StopMeetingBot::class);
});
