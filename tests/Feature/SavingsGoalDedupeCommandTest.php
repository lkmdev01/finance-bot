<?php

use App\Models\SavingsGoal;
use App\Models\User;

it('lista metas duplicadas sem renomear no dry run', function () {
    $user = User::factory()->create([
        'email' => 'cliente@example.com',
    ]);

    SavingsGoal::create([
        'user_id' => $user->id,
        'name' => 'Viagem',
        'target_amount' => 5000,
        'target_date' => now()->addMonths(6)->toDateString(),
        'is_completed' => false,
    ]);

    $duplicate = SavingsGoal::create([
        'user_id' => $user->id,
        'name' => 'Viagem',
        'target_amount' => 300,
        'target_date' => now()->addMonths(2)->toDateString(),
        'is_completed' => false,
    ]);

    $this->artisan('savings:dedupe-goals', ['--user' => 'cliente@example.com'])
        ->expectsOutputToContain('Dry-run')
        ->assertSuccessful();

    expect($duplicate->fresh()->name)->toBe('Viagem');
});

it('renomeia metas duplicadas apenas com apply', function () {
    $user = User::factory()->create([
        'email' => 'cliente@example.com',
    ]);

    SavingsGoal::create([
        'user_id' => $user->id,
        'name' => 'Viagem',
        'target_amount' => 300,
        'target_date' => now()->addMonths(2)->toDateString(),
        'is_completed' => false,
    ]);

    $duplicate = SavingsGoal::create([
        'user_id' => $user->id,
        'name' => 'Viagem',
        'target_amount' => 5000,
        'target_date' => now()->addMonths(6)->toDateString(),
        'is_completed' => false,
    ]);

    $this->artisan('savings:dedupe-goals', [
        '--user' => 'cliente@example.com',
        '--apply' => true,
    ])
        ->expectsOutputToContain('Renomeadas 1 meta')
        ->assertSuccessful();

    expect($duplicate->fresh()->name)->toContain('Viagem - R$ 5.000,00');
});
