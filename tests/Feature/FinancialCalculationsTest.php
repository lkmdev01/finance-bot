<?php

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionRepository;

use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->repository = new TransactionRepository();
    
    // Cria categorias
    $this->incomeCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'name' => 'Salário',
    ]);
    
    $this->expenseCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'name' => 'Supermercado',
    ]);
});

it('calcula saldo disponível corretamente', function () {
    // Cria receitas
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'amount' => 5000.00,
        'date' => now()->subDays(10),
    ]);
    
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'amount' => 2000.00,
        'date' => now()->subDays(5),
    ]);
    
    // Cria despesas
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'amount' => 1500.00,
        'date' => now()->subDays(3),
    ]);
    
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'amount' => 800.00,
        'date' => now()->subDays(1),
    ]);
    
    $totalIncome = $this->repository->getTotalIncomeAllTime($this->user);
    $totalExpenses = $this->repository->getTotalExpensesAllTime($this->user);
    $availableBalance = $totalIncome - $totalExpenses;
    
    expect($totalIncome)->toBe(7000.00);
    expect($totalExpenses)->toBe(2300.00);
    expect($availableBalance)->toBe(4700.00);
});

it('calcula receitas mensais corretamente', function () {
    // Receitas do mês atual
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'amount' => 3000.00,
        'date' => now()->startOfMonth()->addDays(5),
    ]);
    
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'amount' => 1500.00,
        'date' => now()->startOfMonth()->addDays(15),
    ]);
    
    // Receita do mês passado (não deve contar)
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'amount' => 2000.00,
        'date' => now()->subMonth()->startOfMonth(),
    ]);
    
    $monthlyIncome = $this->repository->getMonthlyIncome($this->user, now()->year, now()->month);
    
    expect($monthlyIncome)->toBe(4500.00);
});

it('calcula despesas mensais corretamente', function () {
    // Despesas do mês atual
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'amount' => 500.00,
        'date' => now()->startOfMonth()->addDays(2),
    ]);
    
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'amount' => 300.00,
        'date' => now()->startOfMonth()->addDays(10),
    ]);
    
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'amount' => 200.00,
        'date' => now()->startOfMonth()->addDays(20),
    ]);
    
    // Despesa do mês passado (não deve contar)
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'amount' => 1000.00,
        'date' => now()->subMonth()->startOfMonth(),
    ]);
    
    $monthlyExpenses = $this->repository->getMonthlyExpenses($this->user, now()->year, now()->month);
    
    expect($monthlyExpenses)->toBe(1000.00);
});

it('calcula saldo mensal corretamente', function () {
    // Receitas do mês
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'amount' => 5000.00,
        'date' => now()->startOfMonth()->addDays(5),
    ]);
    
    // Despesas do mês
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'amount' => 2000.00,
        'date' => now()->startOfMonth()->addDays(10),
    ]);
    
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'amount' => 1500.00,
        'date' => now()->startOfMonth()->addDays(20),
    ]);
    
    $monthlyIncome = $this->repository->getMonthlyIncome($this->user, now());
    $monthlyExpenses = $this->repository->getMonthlyExpenses($this->user, now());
    $monthlyBalance = $monthlyIncome - $monthlyExpenses;
    
    expect($monthlyIncome)->toBe(5000.00);
    expect($monthlyExpenses)->toBe(3500.00);
    expect($monthlyBalance)->toBe(1500.00);
});

it('calcula total de receitas de todos os tempos corretamente', function () {
    // Receitas de diferentes meses
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'amount' => 3000.00,
        'date' => now()->subMonths(3),
    ]);
    
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'amount' => 2500.00,
        'date' => now()->subMonths(2),
    ]);
    
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'amount' => 4000.00,
        'date' => now()->subMonth(),
    ]);
    
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'amount' => 3500.00,
        'date' => now(),
    ]);
    
    // Despesa (não deve contar)
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'amount' => 1000.00,
        'date' => now(),
    ]);
    
    $totalIncome = $this->repository->getTotalIncomeAllTime($this->user);
    
    expect($totalIncome)->toBe(13000.00);
});

it('calcula total de despesas de todos os tempos corretamente', function () {
    // Despesas de diferentes meses
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'amount' => 800.00,
        'date' => now()->subMonths(3),
    ]);
    
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'amount' => 1200.00,
        'date' => now()->subMonths(2),
    ]);
    
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'amount' => 1500.00,
        'date' => now()->subMonth(),
    ]);
    
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'amount' => 1000.00,
        'date' => now(),
    ]);
    
    // Receita (não deve contar)
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'amount' => 5000.00,
        'date' => now(),
    ]);
    
    $totalExpenses = $this->repository->getTotalExpensesAllTime($this->user);
    
    expect($totalExpenses)->toBe(4500.00);
});

it('calcula despesas por categoria corretamente', function () {
    $category1 = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'name' => 'Alimentação',
    ]);
    
    $category2 = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'name' => 'Transporte',
    ]);
    
    // Despesas na categoria 1
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category1->id,
        'type' => 'expense',
        'amount' => 500.00,
        'date' => now(),
    ]);
    
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category1->id,
        'type' => 'expense',
        'amount' => 300.00,
        'date' => now(),
    ]);
    
    // Despesas na categoria 2
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category2->id,
        'type' => 'expense',
        'amount' => 200.00,
        'date' => now(),
    ]);
    
    $expensesByCategory = $this->repository->getByCategory($this->user, $category1->id, now()->startOfMonth(), now()->endOfMonth());
    $totalCategory1 = $expensesByCategory->sum('amount');
    
    expect($totalCategory1)->toBe(800.00);
    expect($expensesByCategory->count())->toBe(2);
});

it('calcula agregações mensais otimizadas corretamente', function () {
    // Cria várias transações
    Transaction::factory()->count(5)->create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'amount' => 1000.00,
        'date' => now()->startOfMonth(),
    ]);
    
    Transaction::factory()->count(3)->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'amount' => 500.00,
        'date' => now()->startOfMonth(),
    ]);
    
    $aggregates = $this->repository->getMonthlyAggregates($this->user, now());
    
    expect($aggregates['income'])->toBe(5000.00);
    expect($aggregates['expense'])->toBe(1500.00);
    expect($aggregates['income'] - $aggregates['expense'])->toBe(3500.00);
});

it('calcula agregações de todos os tempos otimizadas corretamente', function () {
    // Cria transações de diferentes meses
    Transaction::factory()->count(10)->create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'amount' => 2000.00,
        'date' => now()->subMonths(rand(1, 6)),
    ]);
    
    Transaction::factory()->count(15)->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'amount' => 500.00,
        'date' => now()->subMonths(rand(1, 6)),
    ]);
    
    $aggregates = $this->repository->getAllTimeAggregates($this->user);
    
    expect($aggregates['income'])->toBe(20000.00);
    expect($aggregates['expense'])->toBe(7500.00);
    expect($aggregates['income'] - $aggregates['expense'])->toBe(12500.00);
});

it('ignora transações de outros usuários nos cálculos', function () {
    $otherUser = User::factory()->create();
    
    // Transações do usuário atual
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'amount' => 5000.00,
        'date' => now(),
    ]);
    
    // Transações de outro usuário (não devem contar)
    Transaction::factory()->create([
        'user_id' => $otherUser->id,
        'type' => 'income',
        'amount' => 10000.00,
        'date' => now(),
    ]);
    
    Transaction::factory()->create([
        'user_id' => $otherUser->id,
        'type' => 'expense',
        'amount' => 5000.00,
        'date' => now(),
    ]);
    
    $totalIncome = $this->repository->getTotalIncomeAllTime($this->user);
    $totalExpenses = $this->repository->getTotalExpensesAllTime($this->user);
    
    expect($totalIncome)->toBe(5000.00);
    expect($totalExpenses)->toBe(0.00);
});

it('calcula corretamente com valores decimais', function () {
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'amount' => 1234.56,
        'date' => now(),
    ]);
    
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'amount' => 789.12,
        'date' => now(),
    ]);
    
    $totalIncome = $this->repository->getTotalIncomeAllTime($this->user);
    $totalExpenses = $this->repository->getTotalExpensesAllTime($this->user);
    $balance = $totalIncome - $totalExpenses;
    
    expect($totalIncome)->toBe(1234.56);
    expect($totalExpenses)->toBe(789.12);
    expect($balance)->toBe(445.44);
});
