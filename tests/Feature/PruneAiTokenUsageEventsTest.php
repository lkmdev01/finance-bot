<?php

use App\Models\AiTokenUsageEvent;
use App\Models\AiUserTokenUsage;
use App\Models\User;
use Carbon\CarbonImmutable;

it('prunes old detailed events while retaining monthly totals and recent events', function () {
    CarbonImmutable::setTestNow('2026-10-29 12:00:00');
    config(['ai.usage_event_retention_days' => 31]);
    $user = User::factory()->create();
    AiUserTokenUsage::create(['user_id' => $user->id, 'period' => '2026-10', 'tokens_used' => 12345]);
    $old = AiTokenUsageEvent::create(['user_id' => $user->id, 'agent_key' => 'agenda', 'total_tokens' => 500]);
    $old->forceFill(['created_at' => now()->subDays(32), 'updated_at' => now()->subDays(32)])->save();
    $recent = AiTokenUsageEvent::create(['user_id' => $user->id, 'agent_key' => 'financial', 'total_tokens' => 200]);

    $this->artisan('ai:prune-token-usage-events')->assertSuccessful();

    expect(AiTokenUsageEvent::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(AiTokenUsageEvent::query()->whereKey($recent->id)->exists())->toBeTrue()
        ->and(AiUserTokenUsage::query()->where('user_id', $user->id)->where('period', '2026-10')->value('tokens_used'))->toBe(12345);
    CarbonImmutable::setTestNow();
});
