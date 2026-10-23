<?php

use App\Jobs\AnalyzeMeetingTranscript;
use App\Jobs\ProcessVexaWebhook;
use App\Models\Meeting;
use App\Models\MeetingTranscriptionTask;
use App\Models\MeetingWebhookEvent;
use App\Models\User;
use App\Services\Meetings\MeetingTranscriptionTaskService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

function signedWorkerPost(TestCase $test, string $path, array $payload = [], array $headers = []): Illuminate\Testing\TestResponse
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $timestamp = (string) now()->timestamp;
    $nonce = bin2hex(random_bytes(16));
    $secret = (string) config('vexa.worker_secret');
    $message = implode("\n", ['POST', $path, $timestamp, $nonce, hash('sha256', $body)]);

    return $test->call('POST', $path, [], [], [], array_merge([
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_WORKER_TIMESTAMP' => $timestamp,
        'HTTP_X_WORKER_NONCE' => $nonce,
        'HTTP_X_WORKER_SIGNATURE' => hash_hmac('sha256', $message, $secret),
    ], $headers), $body);
}

function createMeetingForTranscription(array $attributes = []): Meeting
{
    $user = User::factory()->create(['phone_number' => '+5511999999999']);

    return Meeting::query()->create(array_merge([
        'user_id' => $user->id,
        'platform' => 'google_meet',
        'native_meeting_id_hash' => hash('sha256', 'meet-id'),
        'native_meeting_id' => 'abc-defg-hij',
        'meeting_url' => 'https://meet.google.com/abc-defg-hij',
        'vexa_meeting_id' => '42',
        'vexa_meeting_id_hash' => hash('sha256', '42'),
        'status' => 'awaiting_transcription',
        'participant_notice_attested' => true,
        'participant_notice_attested_at' => now(),
    ], $attributes));
}

beforeEach(function () {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'vexa.worker_secret' => str_repeat('worker-test-secret-', 3),
        'vexa.transcription_lease_seconds' => 600,
        'vexa.transcription_min_words' => 8,
        'vexa.transcription_min_speech_seconds' => 5,
        'queue.default' => 'sync',
    ]);
    Cache::flush();
});

it('claims one pending task and renews its lease with the lease token', function () {
    $meeting = createMeetingForTranscription();
    $task = MeetingTranscriptionTask::query()->create([
        'meeting_id' => $meeting->id, 'status' => 'pending', 'attempts' => 0,
        'max_attempts' => 5, 'available_at' => now(),
    ]);

    $claim = signedWorkerPost($this, '/api/v1/internal/meeting-transcriptions/claim')->assertOk()->json();
    expect($claim['task_id'])->toBe($task->id)
        ->and($claim['meeting']['vexa_meeting_id'])->toBe('42');
    expect($task->fresh()->status)->toBe('processing');

    signedWorkerPost($this, "/api/v1/internal/meeting-transcriptions/{$task->id}/heartbeat", [], [
        'HTTP_X_MEETING_LEASE' => $claim['lease_token'],
    ])->assertOk()->assertJson(['status' => 'renewed']);
    expect($task->fresh()->heartbeat_at)->not->toBeNull();
});

it('reclaims an expired lease after the Fedora worker returns', function () {
    $meeting = createMeetingForTranscription(['status' => 'transcribing']);
    $task = MeetingTranscriptionTask::query()->create([
        'meeting_id' => $meeting->id, 'status' => 'processing', 'attempts' => 1,
        'max_attempts' => 5, 'available_at' => now()->subMinute(),
        'lease_token_hash' => hash('sha256', 'old-lease'), 'lease_expires_at' => now()->subSecond(),
    ]);

    $claim = signedWorkerPost($this, '/api/v1/internal/meeting-transcriptions/claim')->assertOk()->json();
    expect($claim['task_id'])->toBe($task->id)
        ->and($claim['lease_token'])->not->toBe('old-lease')
        ->and($task->fresh()->attempts)->toBe(2)
        ->and($task->fresh()->lease_token_hash)->toBe(hash('sha256', $claim['lease_token']));
});

it('rejects invalid worker signatures and nonce replay', function () {
    $path = '/api/v1/internal/meeting-transcriptions/claim';
    $this->postJson($path, [])->assertUnauthorized();

    $body = '{}';
    $timestamp = (string) now()->timestamp;
    $nonce = 'abcde12345abcde12345';
    $secret = config('vexa.worker_secret');
    $signature = hash_hmac('sha256', implode("\n", ['POST', $path, $timestamp, $nonce, hash('sha256', $body)]), $secret);
    $headers = [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_WORKER_TIMESTAMP' => $timestamp,
        'HTTP_X_WORKER_NONCE' => $nonce, 'HTTP_X_WORKER_SIGNATURE' => $signature,
    ];
    $this->call('POST', $path, [], [], [], $headers, $body)->assertNoContent();
    $this->call('POST', $path, [], [], [], $headers, $body)->assertStatus(409)->assertJson(['status' => 'replay_rejected']);
});

it('marks a task needs_review after its configured retry limit', function () {
    Queue::fake();
    $meeting = createMeetingForTranscription(['status' => 'transcribing']);
    $task = MeetingTranscriptionTask::query()->create([
        'meeting_id' => $meeting->id, 'status' => 'processing', 'attempts' => 2,
        'max_attempts' => 2, 'available_at' => now(), 'lease_token_hash' => hash('sha256', 'lease'),
        'lease_expires_at' => now()->addMinutes(10),
    ]);
    signedWorkerPost($this, "/api/v1/internal/meeting-transcriptions/{$task->id}/fail", [
        'error_code' => 'recording_not_ready',
    ], ['HTTP_X_MEETING_LEASE' => 'lease'])->assertOk()->assertJson(['status' => 'needs_review']);

    expect($task->fresh()->status)->toBe('needs_review')
        ->and($meeting->fresh()->status)->toBe('needs_review');
    Queue::assertPushed(\App\Jobs\SendMeetingReviewNotice::class, 1);
});

it('schedules bounded retry with backoff after a transient worker failure', function () {
    $meeting = createMeetingForTranscription(['status' => 'transcribing']);
    $task = MeetingTranscriptionTask::query()->create([
        'meeting_id' => $meeting->id, 'status' => 'processing', 'attempts' => 1,
        'max_attempts' => 3, 'available_at' => now(), 'lease_token_hash' => hash('sha256', 'lease'),
        'lease_expires_at' => now()->addMinutes(10),
    ]);
    signedWorkerPost($this, "/api/v1/internal/meeting-transcriptions/{$task->id}/fail", [
        'error_code' => 'recording_not_ready',
    ], ['HTTP_X_MEETING_LEASE' => 'lease'])->assertOk()->assertJson(['status' => 'retry_scheduled']);

    expect($task->fresh()->status)->toBe('pending')
        ->and($task->fresh()->available_at->isFuture())->toBeTrue()
        ->and($task->fresh()->lease_token_hash)->toBeNull()
        ->and($meeting->fresh()->status)->toBe('awaiting_transcription');
});

it('purges expired Laravel meeting data without calling Vexa or deleting its recording', function () {
    $meeting = createMeetingForTranscription([
        'status' => 'reported',
        'expires_at' => now()->subDay(),
        'transcript_expires_at' => now()->subDay(),
        'transcript' => 'texto sintético de reunião',
        'transcript_segments' => [['start' => 1.0, 'end' => 2.0, 'text' => 'texto sintético']],
        'summary' => 'resumo sintético',
    ]);
    $task = MeetingTranscriptionTask::query()->create([
        'meeting_id' => $meeting->id, 'status' => 'received', 'attempts' => 1,
        'max_attempts' => 5, 'available_at' => now(), 'recording_id' => '17',
        'media_file_id' => '29', 'processing_metadata' => ['language' => 'pt'],
    ]);
    MeetingWebhookEvent::query()->create([
        'event_hash' => hash('sha256', 'old-event'), 'meeting_id' => $meeting->id,
        'event_type' => 'meeting.completed', 'payload' => ['event_type' => 'meeting.completed'],
        'received_at' => now()->subDays(100),
    ]);
    \Illuminate\Support\Facades\Http::fake();

    $this->artisan('meetings:purge-expired-data')->assertExitCode(0);

    expect($meeting->fresh()->status)->toBe('expired')
        ->and($meeting->fresh()->transcript)->toBeNull()
        ->and($meeting->fresh()->transcript_segments)->toBeNull()
        ->and($meeting->fresh()->summary)->toBeNull()
        ->and($meeting->fresh()->meeting_url)->toBeNull()
        ->and($task->fresh()->status)->toBe('expired')
        ->and($task->fresh()->recording_id)->toBeNull()
        ->and(MeetingWebhookEvent::query()->count())->toBe(0);
    \Illuminate\Support\Facades\Http::assertNothingSent();
});

it('accepts a valid non-correlatable status change without mutating a meeting', function () {
    $meeting = createMeetingForTranscription(['status' => 'active']);
    $payload = [
        'event_id' => 'evt_status_without_meeting_id',
        'event_type' => 'meeting.status_change',
        'api_version' => '2026-03-01',
        'created_at' => now()->toIso8601String(),
        'data' => [
            'meeting' => ['connection_id' => 'opaque-connection', 'status' => 'completed'],
            'status_change' => [
                'from' => 'active', 'to' => 'completed', 'reason' => 'meeting ended',
                'timestamp' => now()->toIso8601String(), 'transition_source' => 'bot_callback',
            ],
        ],
    ];
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $timestamp = (string) now()->timestamp;
    $secret = 'webhook-test-secret';
    config(['vexa.webhook_secret' => $secret]);
    $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    $this->call('POST', '/api/v1/webhook/vexa', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
        'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
    ], $body)->assertStatus(202)->assertJson(['status' => 'accepted_unmatched']);

    expect($meeting->fresh()->status)->toBe('active')
        ->and(MeetingWebhookEvent::query()->whereNull('meeting_id')->count())->toBe(1);

    unset($payload['data']['status_change']);
    $invalidBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $invalidSignature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$invalidBody, $secret);
    $this->call('POST', '/api/v1/webhook/vexa', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
        'HTTP_X_WEBHOOK_SIGNATURE' => $invalidSignature,
    ], $invalidBody)->assertStatus(422)->assertJson(['status' => 'invalid_event']);
    expect(MeetingWebhookEvent::query()->whereNull('meeting_id')->count())->toBe(1);
});

it('preserves typed meeting started and completed events and creates one transcription task', function () {
    $meeting = createMeetingForTranscription(['status' => 'requested']);
    $started = MeetingWebhookEvent::query()->create([
        'event_hash' => hash('sha256', 'started-event'), 'meeting_id' => $meeting->id,
        'event_type' => 'meeting.started', 'payload' => ['data' => ['meeting' => ['status' => 'active']]],
        'received_at' => now(),
    ]);
    (new ProcessVexaWebhook($started->id))->handle(app(MeetingTranscriptionTaskService::class));
    expect($meeting->fresh()->status)->toBe('active');

    $completed = MeetingWebhookEvent::query()->create([
        'event_hash' => hash('sha256', 'completed-event'), 'meeting_id' => $meeting->id,
        'event_type' => 'meeting.completed', 'payload' => ['data' => ['meeting' => ['status' => 'completed']]],
        'received_at' => now(),
    ]);
    (new ProcessVexaWebhook($completed->id))->handle(app(MeetingTranscriptionTaskService::class));
    (new ProcessVexaWebhook($completed->id))->handle(app(MeetingTranscriptionTaskService::class));

    expect($meeting->fresh()->status)->toBe('awaiting_transcription')
        ->and(MeetingTranscriptionTask::query()->where('meeting_id', $meeting->id)->count())->toBe(1);
});

it('persists a sufficient transcript encrypted and safely requeues analysis on duplicate delivery', function () {
    Queue::fake();
    $meeting = createMeetingForTranscription();
    $task = MeetingTranscriptionTask::query()->create([
        'meeting_id' => $meeting->id, 'status' => 'processing', 'attempts' => 1,
        'max_attempts' => 5, 'available_at' => now(), 'lease_token_hash' => hash('sha256', 'lease'),
        'lease_expires_at' => now()->addMinutes(10),
    ]);
    $segments = [
        ['start' => 2.25, 'end' => 8.5, 'text' => 'A equipe aprovou o orçamento e definiu a entrega para sexta feira.'],
        ['start' => 11.0, 'end' => 18.0, 'text' => 'Mariana ficará responsável por enviar o documento ao cliente.'],
    ];
    $path = "/api/v1/internal/meeting-transcriptions/{$task->id}/result";
    signedWorkerPost($this, $path, [
        'language' => 'pt', 'model' => 'Systran/faster-whisper-small',
        'recording_id' => '123', 'media_file_id' => '456',
        'audio_duration_seconds' => 60, 'speech_duration_seconds' => 20,
        'segments' => $segments,
    ], ['HTTP_X_MEETING_LEASE' => 'lease'])->assertOk()->assertJson(['status' => 'accepted']);

    $stored = $meeting->fresh();
    expect($stored->transcript_segments[0]['start'])->toBe(2.25)
        ->and($stored->transcript)->toContain('A equipe aprovou o orçamento')
        ->and(DB::table('meetings')->where('id', $meeting->id)->value('transcript'))->not->toContain('A equipe aprovou')
        ->and($task->fresh()->status)->toBe('received');
    Queue::assertPushed(AnalyzeMeetingTranscript::class, 1);

    signedWorkerPost($this, $path, [
        'language' => 'pt', 'model' => 'Systran/faster-whisper-small',
        'recording_id' => '123', 'media_file_id' => '456',
        'audio_duration_seconds' => 60, 'speech_duration_seconds' => 20,
        'segments' => $segments,
    ], ['HTTP_X_MEETING_LEASE' => 'lease'])->assertOk()->assertJson(['status' => 'duplicate']);
    Queue::assertPushed(AnalyzeMeetingTranscript::class, 2);
});

it('marks an empty or insufficient transcript for review and never dispatches the MeetingAgent job', function () {
    Queue::fake();
    $meeting = createMeetingForTranscription();
    $task = MeetingTranscriptionTask::query()->create([
        'meeting_id' => $meeting->id, 'status' => 'processing', 'attempts' => 1,
        'max_attempts' => 5, 'available_at' => now(), 'lease_token_hash' => hash('sha256', 'lease'),
        'lease_expires_at' => now()->addMinutes(10),
    ]);
    signedWorkerPost($this, "/api/v1/internal/meeting-transcriptions/{$task->id}/result", [
        'language' => 'pt', 'model' => 'Systran/faster-whisper-small',
        'recording_id' => '123', 'media_file_id' => '456',
        'audio_duration_seconds' => 30, 'speech_duration_seconds' => 0,
        'segments' => [],
    ], ['HTTP_X_MEETING_LEASE' => 'lease'])->assertOk()->assertJson(['status' => 'needs_review']);

    expect($meeting->fresh()->status)->toBe('needs_review')
        ->and($task->fresh()->status)->toBe('needs_review');
    Queue::assertNotPushed(AnalyzeMeetingTranscript::class);
});
