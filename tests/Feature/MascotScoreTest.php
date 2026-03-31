<?php

use App\Models\BankAccount;
use App\Models\Budget;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\MascotAchievementUnlock;
use App\Models\MascotProfile;
use App\Models\SavingsGoal;
use App\Models\SavingsGoalDeposit;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MascotScoreService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;

it('calcula score, nivel e conquistas do mascote para um usuario saudavel', function () {
    $user = User::factory()->create();

    $incomeCategory = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'income',
    ]);

    $expenseCategory = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
    ]);

    BankAccount::create([
        'user_id' => $user->id,
        'name' => 'Conta principal',
        'type' => 'checking',
        'opening_balance' => 1000,
        'currency' => 'BRL',
        'is_active' => true,
    ]);

    CreditCard::create([
        'user_id' => $user->id,
        'name' => 'Cartao Orbita',
        'brand' => 'Visa',
        'credit_limit' => 3000,
        'opening_balance' => 0,
        'closing_day' => 10,
        'due_day' => 20,
        'is_active' => true,
    ]);

    Budget::factory()->create([
        'user_id' => $user->id,
        'category_id' => $expenseCategory->id,
        'amount' => 1500,
        'period' => 'monthly',
        'year' => now()->year,
        'month' => now()->month,
    ]);

    foreach (range(0, 6) as $daysAgo) {
        Transaction::factory()->create([
            'user_id' => $user->id,
            'category_id' => $incomeCategory->id,
            'type' => 'income',
            'amount' => 500,
            'date' => now()->subDays($daysAgo),
        ]);
    }

    Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $expenseCategory->id,
        'type' => 'expense',
        'amount' => 600,
        'date' => now()->subDays(1),
    ]);

    $goal = SavingsGoal::factory()->create([
        'user_id' => $user->id,
        'target_amount' => 1000,
    ]);

    SavingsGoalDeposit::factory()->create([
        'savings_goal_id' => $goal->id,
        'amount' => 300,
        'deposit_date' => now()->subDay(),
    ]);

    $summary = app(MascotScoreService::class)->sync($user);

    expect($summary['score'])->toBeGreaterThanOrEqual(70);
    expect($summary['level'])->toBeGreaterThanOrEqual(2);
    expect($summary['current_streak'])->toBe(7);
    expect($summary['badges_unlocked'])->toBeGreaterThanOrEqual(5);
    expect($summary['mood']['key'])->toBe('celebrating');

    assertDatabaseHas('mascot_profiles', [
        'user_id' => $user->id,
        'current_streak' => 7,
    ]);

    expect(MascotProfile::where('user_id', $user->id)->exists())->toBeTrue();
    expect(MascotAchievementUnlock::where('user_id', $user->id)->count())->toBeGreaterThanOrEqual(5);
});

it('identifica quando o usuario precisa de atencao', function () {
    $user = User::factory()->create();

    $incomeCategory = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'income',
    ]);

    $expenseCategory = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
    ]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $incomeCategory->id,
        'type' => 'income',
        'amount' => 400,
        'date' => now(),
    ]);

    Transaction::factory()->count(2)->create([
        'user_id' => $user->id,
        'category_id' => $expenseCategory->id,
        'type' => 'expense',
        'amount' => 700,
        'date' => now(),
    ]);

    $summary = app(MascotScoreService::class)->sync($user);

    expect($summary['score'])->toBeLessThan(50);
    expect($summary['mood']['key'])->toBe('attention');
    expect($summary['focus_area']['key'])->not->toBeEmpty();
});

it('permite abrir a pagina do mascote autenticado', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->get(route(config('mascot.route_name', 'mascot.index')))
        ->assertOk()
        ->assertSee(config('mascot.name', 'Orbita'))
        ->assertSee('foguete virtual', false);
});
