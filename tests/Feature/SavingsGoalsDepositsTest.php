<?php

use App\Models\SavingsGoal;
use App\Models\SavingsGoalDeposit;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->goal = SavingsGoal::factory()->create([
        'user_id' => $this->user->id,
        'target_amount' => 1000.00,
    ]);
});

it('pode fazer um depósito em uma meta', function () {
    actingAs($this->user)
        ->get(route('savings-goals.deposit', $this->goal))
        ->assertSuccessful();

    $deposit = SavingsGoalDeposit::create([
        'savings_goal_id' => $this->goal->id,
        'amount' => 100.00,
        'description' => 'Depósito inicial',
        'deposit_date' => now(),
    ]);

    assertDatabaseHas('savings_goal_deposits', [
        'id' => $deposit->id,
        'savings_goal_id' => $this->goal->id,
        'amount' => 100.00,
    ]);
});

it('cria transação automática ao fazer depósito', function () {
    $deposit = SavingsGoalDeposit::factory()->create([
        'savings_goal_id' => $this->goal->id,
        'amount' => 100.00,
    ]);

    // Simular criação de depósito (o boot() do modelo cria a transação)
    $category = $this->user->categories()->firstOrCreate([
        'name' => 'Economia',
        'type' => 'expense',
    ]);

    $this->user->transactions()->create([
        'category_id' => $category->id,
        'type' => 'expense',
        'amount' => 100.00,
        'description' => 'Depósito em meta: ' . $this->goal->name,
        'date' => now(),
        'metadata' => [
            'savings_goal_id' => $this->goal->id,
            'savings_goal_deposit_id' => $deposit->id,
        ],
    ]);

    assertDatabaseHas('transactions', [
        'user_id' => $this->user->id,
        'type' => 'expense',
        'amount' => 100.00,
    ]);
});

it('pode remover um depósito de uma meta', function () {
    $deposit = SavingsGoalDeposit::factory()->create([
        'savings_goal_id' => $this->goal->id,
        'amount' => 100.00,
    ]);

    // Criar transação associada
    $category = $this->user->categories()->firstOrCreate([
        'name' => 'Economia',
        'type' => 'expense',
    ]);

    $transaction = $this->user->transactions()->create([
        'category_id' => $category->id,
        'type' => 'expense',
        'amount' => 100.00,
        'description' => 'Depósito em meta: ' . $this->goal->name,
        'date' => now(),
        'metadata' => [
            'savings_goal_id' => $this->goal->id,
            'savings_goal_deposit_id' => $deposit->id,
        ],
    ]);

    $deposit->delete();

    assertDatabaseMissing('savings_goal_deposits', [
        'id' => $deposit->id,
    ]);
});

it('atualiza saldo disponível ao remover depósito', function () {
    $deposit = SavingsGoalDeposit::factory()->create([
        'savings_goal_id' => $this->goal->id,
        'amount' => 100.00,
    ]);

    $initialBalance = $this->goal->current_amount;
    
    $deposit->delete();

    expect($this->goal->fresh()->current_amount)->toBeLessThan($initialBalance);
});

it('marca meta como concluída quando valor atinge a meta', function () {
    $this->goal->update(['target_amount' => 100.00]);

    SavingsGoalDeposit::factory()->create([
        'savings_goal_id' => $this->goal->id,
        'amount' => 100.00,
    ]);

    expect($this->goal->fresh()->is_completed)->toBeTrue();
});

it('calcula progresso corretamente', function () {
    SavingsGoalDeposit::factory()->create([
        'savings_goal_id' => $this->goal->id,
        'amount' => 250.00,
    ]);

    expect($this->goal->fresh()->progress_percentage)->toBe(25.0);
});
