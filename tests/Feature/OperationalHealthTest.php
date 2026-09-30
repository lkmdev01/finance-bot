<?php

use App\Jobs\RecordQueueHeartbeat;
use App\Models\AuditLog;
use App\Services\AlertService;
use App\Services\QueueHeartbeatService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Cache::flush();
    config([
        'queue.default' => 'database',
        'queue_health.queue' => 'default',
        'queue_health.max_age_seconds' => 60,
    ]);
});

it('reports database whatsapp and a recent queue heartbeat as healthy', function () {
    Http::fake(['*/status' => Http::response(['connected' => true])]);
    app(QueueHeartbeatService::class)->recordProcessed('healthy-probe');

    $this->getJson(route('health'))
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
            'services' => [
                'database' => true,
                'whatsapp' => true,
                'queue' => true,
            ],
        ]);
});

it('reports a stale queued probe when no worker processes it', function () {
    Http::fake(['*/status' => Http::response(['connected' => true])]);
    app(QueueHeartbeatService::class)->dispatchProbe();

    $this->travel(61)->seconds();

    $this->getJson(route('health'))
        ->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('services.queue', false)
        ->assertJsonPath('details.queue.state', 'probe_stale');
});

it('does not expose provider exception messages from the public health endpoint', function () {
    Http::fake(fn () => throw new RuntimeException('internal provider secret'));
    app(QueueHeartbeatService::class)->recordProcessed('healthy-probe');

    $response = $this->getJson(route('health'))->assertStatus(503);

    expect($response->getContent())->not->toContain('internal provider secret');
});

it('dispatches and records a queue heartbeat probe', function () {
    Queue::fake();

    expect(Artisan::call('queue:heartbeat'))->toBe(0);

    Queue::assertPushed(RecordQueueHeartbeat::class, function (RecordQueueHeartbeat $job) {
        $job->handle(app(QueueHeartbeatService::class));

        return true;
    });

    expect(app(QueueHeartbeatService::class)->snapshot())
        ->healthy->toBeTrue()
        ->state->toBe('processed');
});

it('alerts on a disconnected session instead of warning about normal inactivity', function () {
    Mail::fake();
    Http::fake(['*/status' => Http::response(['connected' => false])]);
    app(QueueHeartbeatService::class)->recordProcessed('healthy-probe');

    app(AlertService::class)->checkAlerts();

    expect(AuditLog::query()->where('action', 'alert')->count())->toBe(1)
        ->and(AuditLog::query()->value('metadata')['alert_type'])->toBe('whatsapp_disconnected');
});
