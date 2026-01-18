<?php

declare(strict_types=1);

use App\Models\SavingsGoal;
use App\Models\SavingsGoalAlert;
use App\Models\SavingsGoalDeposit;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('can access savings goal alerts page', function () {
    $user = User::factory()->create();
    $goal = SavingsGoal::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->get(route('savings-goals.alerts', $goal))
        ->assertSuccessful();
});

it('can create a milestone alert', function () {
    $user = User::factory()->create();
    $goal = SavingsGoal::factory()->create([
        'user_id' => $user->id,
        'target_amount' => 1000.00,
    ]);

    $alert = SavingsGoalAlert::factory()->milestone()->create([
        'user_id' => $user->id,
        'savings_goal_id' => $goal->id,
        'threshold_percentage' => 50.00,
    ]);

    expect($alert->type)->toBe('milestone');
    expect((float) $alert->threshold_percentage)->toBe(50.00);
});

it('can create a deadline alert', function () {
    $user = User::factory()->create();
    $goal = SavingsGoal::factory()->create([
        'user_id' => $user->id,
        'target_date' => now()->addDays(30),
    ]);

    $alert = SavingsGoalAlert::factory()->deadline()->create([
        'user_id' => $user->id,
        'savings_goal_id' => $goal->id,
        'days_before_deadline' => 7,
    ]);

    expect($alert->type)->toBe('deadline');
    expect($alert->days_before_deadline)->toBe(7);
});

it('triggers milestone alert when threshold is reached', function () {
    $user = User::factory()->create();
    $goal = SavingsGoal::factory()->create([
        'user_id' => $user->id,
        'target_amount' => 1000.00,
    ]);

    // Criar depósitos para atingir 50%
    SavingsGoalDeposit::factory()->create([
        'savings_goal_id' => $goal->id,
        'amount' => 500.00,
    ]);

    $alert = SavingsGoalAlert::factory()->milestone()->create([
        'user_id' => $user->id,
        'savings_goal_id' => $goal->id,
        'threshold_percentage' => 50.00,
    ]);

    expect($alert->shouldTrigger())->toBeTrue();
});

it('triggers deadline alert when days before deadline', function () {
    $user = User::factory()->create();
    $goal = SavingsGoal::factory()->create([
        'user_id' => $user->id,
        'target_date' => now()->addDays(5),
    ]);

    $alert = SavingsGoalAlert::factory()->deadline()->create([
        'user_id' => $user->id,
        'savings_goal_id' => $goal->id,
        'days_before_deadline' => 7,
    ]);

    expect($alert->shouldTrigger())->toBeTrue();
});
