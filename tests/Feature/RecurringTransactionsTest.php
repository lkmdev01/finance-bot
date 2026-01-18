<?php

use App\Models\Category;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
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

it('pode listar transações recorrentes', function () {
    RecurringTransaction::factory()->count(3)->create([
        'user_id' => $this->user->id,
    ]);

    actingAs($this->user)
        ->get(route('recurring-transactions.index'))
        ->assertSuccessful()
        ->assertSee('Transações Recorrentes');
});

it('pode criar uma transação recorrente mensal', function () {
    actingAs($this->user)
        ->get(route('recurring-transactions.create'))
        ->assertSuccessful();

    $recurring = RecurringTransaction::create([
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
        'type' => 'expense',
        'amount' => 500.00,
        'description' => 'Aluguel',
        'frequency' => 'monthly',
        'start_date' => now(),
        'day_of_month' => 5,
        'is_active' => true,
    ]);

    assertDatabaseHas('recurring_transactions', [
        'id' => $recurring->id,
        'type' => 'expense',
        'frequency' => 'monthly',
        'day_of_month' => 5,
    ]);
});

it('pode criar uma transação recorrente semanal', function () {
    $recurring = RecurringTransaction::create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'amount' => 100.00,
        'description' => 'Freelance semanal',
        'frequency' => 'weekly',
        'start_date' => now(),
        'day_of_week' => 1, // Segunda-feira
        'is_active' => true,
    ]);

    assertDatabaseHas('recurring_transactions', [
        'id' => $recurring->id,
        'frequency' => 'weekly',
        'day_of_week' => 1,
    ]);
});

it('pode editar uma transação recorrente', function () {
    $recurring = RecurringTransaction::factory()->create([
        'user_id' => $this->user->id,
        'amount' => 100.00,
    ]);

    actingAs($this->user)
        ->get(route('recurring-transactions.edit', $recurring))
        ->assertSuccessful();

    $recurring->update(['amount' => 200.00]);

    assertDatabaseHas('recurring_transactions', [
        'id' => $recurring->id,
        'amount' => 200.00,
    ]);
});

it('pode excluir uma transação recorrente', function () {
    $recurring = RecurringTransaction::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $recurring->delete();

    assertDatabaseMissing('recurring_transactions', [
        'id' => $recurring->id,
    ]);
});

it('pode ativar e desativar uma transação recorrente', function () {
    $recurring = RecurringTransaction::factory()->create([
        'user_id' => $this->user->id,
        'is_active' => true,
    ]);

    $recurring->update(['is_active' => false]);

    expect($recurring->fresh()->is_active)->toBeFalse();
});

it('processa transação recorrente diária corretamente', function () {
    $recurring = RecurringTransaction::factory()->create([
        'user_id' => $this->user->id,
        'frequency' => 'daily',
        'is_active' => true,
        'start_date' => now()->subDay(),
        'last_processed_at' => null,
    ]);

    expect($recurring->shouldProcessToday())->toBeTrue();
});

it('não processa transação recorrente se já foi processada hoje', function () {
    $recurring = RecurringTransaction::factory()->create([
        'user_id' => $this->user->id,
        'frequency' => 'daily',
        'is_active' => true,
        'start_date' => now()->subDay(),
        'last_processed_at' => now(),
    ]);

    expect($recurring->shouldProcessToday())->toBeFalse();
});

it('processa transação recorrente mensal no dia correto', function () {
    $recurring = RecurringTransaction::factory()->create([
        'user_id' => $this->user->id,
        'frequency' => 'monthly',
        'is_active' => true,
        'start_date' => now()->subMonth(),
        'day_of_month' => now()->day,
        'last_processed_at' => null,
    ]);

    expect($recurring->shouldProcessToday())->toBeTrue();
});

it('não processa transação recorrente mensal em dia diferente', function () {
    $recurring = RecurringTransaction::factory()->create([
        'user_id' => $this->user->id,
        'frequency' => 'monthly',
        'is_active' => true,
        'start_date' => now()->subMonth(),
        'day_of_month' => (now()->day % 28) + 1, // Dia diferente de hoje
        'last_processed_at' => null,
    ]);

    expect($recurring->shouldProcessToday())->toBeFalse();
});
