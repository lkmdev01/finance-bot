<?php

use App\Exceptions\AiUserTokenLimitExceeded;
use App\Models\AiUserTokenUsage;
use App\Models\User;
use App\Services\AiUserTokenBudget;
use Carbon\CarbonImmutable;
it('records provider reported token use for each user and month', function () {
    CarbonImmutable::setTestNow('2026-10-28 12:00:00');
    config(['ai.user_monthly_token_limit' => 20000]);
    $user = User::factory()->create();

    app(AiUserTokenBudget::class)->run($user, fn () => (object) [
        'usage' => (object) ['promptTokens' => 120, 'completionTokens' => 30],
    ]);

    expect(app(AiUserTokenBudget::class)->currentUsage($user))->toBe(150)
        ->and(AiUserTokenUsage::query()->where('user_id', $user->id)->value('period'))->toBe('2026-10');

    CarbonImmutable::setTestNow();
});

it('blocks further agent calls after a user reaches their monthly token limit', function () {
    CarbonImmutable::setTestNow('2026-10-28 12:00:00');
    config(['ai.user_monthly_token_limit' => 20000]);
    $user = User::factory()->create();
    AiUserTokenUsage::create(['user_id' => $user->id, 'period' => '2026-10', 'tokens_used' => 20000]);
    $called = false;

    expect(fn () => app(AiUserTokenBudget::class)->run($user, function () use (&$called) {
        $called = true;

        return (object) ['usage' => (object) ['promptTokens' => 1, 'completionTokens' => 1]];
    }))->toThrow(AiUserTokenLimitExceeded::class);

    expect($called)->toBeFalse();
    CarbonImmutable::setTestNow();
});

it('starts a separate token allowance in the next calendar month', function () {
    CarbonImmutable::setTestNow('2026-10-31 23:59:00');
    config(['ai.user_monthly_token_limit' => 20000]);
    $user = User::factory()->create();
    AiUserTokenUsage::create(['user_id' => $user->id, 'period' => '2026-10', 'tokens_used' => 20000]);

    CarbonImmutable::setTestNow('2026-11-01 00:01:00');
    $result = app(AiUserTokenBudget::class)->run($user, fn () => (object) [
        'usage' => (object) ['promptTokens' => 4, 'completionTokens' => 6],
    ]);

    expect(app(AiUserTokenBudget::class)->currentUsage($user))->toBe(10)
        ->and(AiUserTokenUsage::query()->where('user_id', $user->id)->where('period', '2026-11')->exists())->toBeTrue();

    CarbonImmutable::setTestNow();
});
