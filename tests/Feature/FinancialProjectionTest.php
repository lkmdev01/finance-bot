<?php

use App\Models\FinancialProjection;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinancialProjectionService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->projectionService = app(FinancialProjectionService::class);
});

it('gera projeções financeiras para próximos meses', function () {
    // Criar algumas transações históricas
    Transaction::factory()->count(10)->create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'amount' => 1000.00,
    ]);

    Transaction::factory()->count(10)->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'amount' => 500.00,
    ]);

    $projections = $this->projectionService->generateProjections($this->user, 6);

    expect($projections)->toHaveCount(6);
    expect($projections[0])->toHaveKeys(['date', 'projected_balance', 'projected_income', 'projected_expenses']);
});

it('considera transações recorrentes nas projeções', function () {
    RecurringTransaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'amount' => 5000.00,
        'frequency' => 'monthly',
        'is_active' => true,
        'start_date' => now()->subMonth(),
    ]);

    $projections = $this->projectionService->generateProjections($this->user, 3);

    expect($projections[0]['projected_income'])->toBeGreaterThan(0);
});

it('salva projeções no banco de dados', function () {
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'amount' => 1000.00,
    ]);

    $this->projectionService->generateProjections($this->user, 3);

    expect(FinancialProjection::where('user_id', $this->user->id)->count())->toBe(3);
});
