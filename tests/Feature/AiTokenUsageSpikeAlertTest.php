<?php

use App\Models\AiTokenUsageEvent;
use App\Models\User;
use App\Notifications\AiTokenUsageSpikeNotification;
use App\Services\AiTokenUsageSpikeAlertService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

it('alerts the owner once when usage crosses the hourly threshold', function () {
    CarbonImmutable::setTestNow('2026-10-29 10:30:00');
    config(['ai.usage_alert_hourly_threshold' => 5000]);
    Notification::fake();
    $user = User::factory()->create();
    AiTokenUsageEvent::create(['user_id' => $user->id, 'agent_key' => 'agenda', 'total_tokens' => 3200]);
    $latest = AiTokenUsageEvent::create(['user_id' => $user->id, 'agent_key' => 'financial', 'total_tokens' => 2000]);

    $service = app(AiTokenUsageSpikeAlertService::class);
    $service->check($user, $latest);
    $service->check($user, $latest);

    Notification::assertSentToTimes($user, AiTokenUsageSpikeNotification::class, 1);
    expect($user->notifications()->where('data->type', 'ai_token_usage_spike')->count())->toBe(0);
    CarbonImmutable::setTestNow();
});

it('does not alert before threshold or when alerts are disabled', function () {
    CarbonImmutable::setTestNow('2026-10-29 10:30:00');
    Notification::fake();
    $user = User::factory()->create();
    $latest = AiTokenUsageEvent::create(['user_id' => $user->id, 'agent_key' => 'agenda', 'total_tokens' => 4999]);
    config(['ai.usage_alert_hourly_threshold' => 5000]);

    app(AiTokenUsageSpikeAlertService::class)->check($user, $latest);
    config(['ai.usage_alert_hourly_threshold' => 0]);
    app(AiTokenUsageSpikeAlertService::class)->check($user, $latest);

    Notification::assertNothingSent();
    CarbonImmutable::setTestNow();
});
