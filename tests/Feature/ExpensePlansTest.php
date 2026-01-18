<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\ExpensePlan;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('can access expense plans index page', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->get(route('expense-plans.index'))
        ->assertSuccessful();
});

it('can create an expense plan', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->get(route('expense-plans.create'))
        ->assertSuccessful();
});

it('can update spent amount on expense plan', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);

    $plan = ExpensePlan::factory()->create([
        'user_id' => $user->id,
        'start_date' => now()->subDays(5),
        'end_date' => now()->addDays(25),
        'categories' => [$category->id],
    ]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'type' => 'expense',
        'amount' => 150.00,
        'date' => now()->subDays(2),
    ]);

    $plan->updateSpentAmount();

    expect((float) $plan->fresh()->spent_amount)->toBe(150.00);
});

it('calculates remaining amount correctly', function () {
    $plan = ExpensePlan::factory()->create([
        'planned_amount' => 1000.00,
        'spent_amount' => 350.00,
    ]);

    expect($plan->remaining_amount)->toBe(650.00);
});

it('calculates progress percentage correctly', function () {
    $plan = ExpensePlan::factory()->create([
        'planned_amount' => 1000.00,
        'spent_amount' => 500.00,
    ]);

    expect($plan->progress_percentage)->toBe(50.0);
});

it('detects when plan is exceeded', function () {
    $plan = ExpensePlan::factory()->create([
        'planned_amount' => 1000.00,
        'spent_amount' => 1200.00,
    ]);

    expect($plan->is_exceeded)->toBeTrue();
});
