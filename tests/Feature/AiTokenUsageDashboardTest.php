<?php

use App\Models\AiTokenUsageEvent;
use App\Models\AiUserTokenUsage;
use App\Models\User;
use App\Notifications\AiTokenUsageSpikeNotification;

it('shows token usage by agent only to the authenticated user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    AiUserTokenUsage::create(['user_id' => $owner->id, 'period' => now()->format('Y-m'), 'tokens_used' => 900]);
    AiTokenUsageEvent::create(['user_id' => $owner->id, 'agent_key' => 'agenda', 'input_tokens' => 600, 'output_tokens' => 300, 'total_tokens' => 900]);
    AiTokenUsageEvent::create(['user_id' => $other->id, 'agent_key' => 'private-other-user-agent', 'total_tokens' => 6000]);
    $owner->notify(new AiTokenUsageSpikeNotification(5200, 5000, now()->startOfHour()->toIso8601String()));
    $other->notify(new AiTokenUsageSpikeNotification(9000, 5000, now()->startOfHour()->toIso8601String()));

    $this->withoutVite()->actingAs($owner)->get(route('ai-usage.index'))
        ->assertOk()
        ->assertSee('Uso de IA')
        ->assertSee('Agenda')
        ->assertSee('Alertas recentes de consumo')
        ->assertSee('5.200 tokens')
        ->assertSee('900')
        ->assertDontSee('9.000 tokens')
        ->assertDontSee('private-other-user-agent');
});
