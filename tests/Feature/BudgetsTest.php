<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\get;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->category = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
    ]);
});

it('pode listar orçamentos', function () {
    Budget::factory()->count(3)->create([
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
    ]);

    actingAs($this->user)
        ->get(route('budgets.index'))
        ->assertSuccessful()
        ->assertSee('Orçamentos');
});

it('pode criar um orçamento', function () {
    actingAs($this->user)
        ->get(route('budgets.create'))
        ->assertSuccessful();

    $budget = Budget::create([
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
        'amount' => 1000.00,
        'period' => 'monthly',
        'year' => now()->year,
        'month' => now()->month,
    ]);

    assertDatabaseHas('budgets', [
        'id' => $budget->id,
        'amount' => 1000.00,
        'period' => 'monthly',
    ]);
});

it('pode editar um orçamento', function () {
    $budget = Budget::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
    ]);

    actingAs($this->user)
        ->get(route('budgets.edit', $budget))
        ->assertSuccessful();

    $budget->update([
        'amount' => 1500.00,
    ]);

    assertDatabaseHas('budgets', [
        'id' => $budget->id,
        'amount' => 1500.00,
    ]);
});

it('pode excluir um orçamento', function () {
    $budget = Budget::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
    ]);

    $budget->delete();

    assertDatabaseMissing('budgets', [
        'id' => $budget->id,
    ]);
});

it('calcula corretamente o valor gasto do orçamento', function () {
    $budget = Budget::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
        'amount' => 1000.00,
        'period' => 'monthly',
        'year' => now()->year,
        'month' => now()->month,
    ]);

    // Criar transações de despesa para a categoria
    \App\Models\Transaction::factory()->count(2)->create([
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
        'type' => 'expense',
        'amount' => 300.00,
        'date' => now(),
    ]);

    expect($budget->spent)->toBe(600.00);
    expect($budget->remaining)->toBe(400.00);
    expect($budget->percentage_used)->toBe(60.0);
});
