<?php

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AIService;
use App\Services\TransactionRepository;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->repository = new TransactionRepository();
});

it('calcula dados financeiros com performance aceitável', function () {
    // Cria um volume significativo de transações
    Transaction::factory()->count(100)->create([
        'user_id' => $this->user->id,
        'type' => 'income',
    ]);
    
    Transaction::factory()->count(150)->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
    ]);
    
    $startTime = microtime(true);
    
    $totalIncome = $this->repository->getTotalIncomeAllTime($this->user);
    $totalExpenses = $this->repository->getTotalExpensesAllTime($this->user);
    $monthlyIncome = $this->repository->getMonthlyIncome($this->user, now());
    $monthlyExpenses = $this->repository->getMonthlyExpenses($this->user, now());
    
    $executionTime = (microtime(true) - $startTime) * 1000; // em milissegundos
    
    expect($executionTime)->toBeLessThan(500); // Deve executar em menos de 500ms
    expect($totalIncome)->toBeGreaterThan(0);
    expect($totalExpenses)->toBeGreaterThan(0);
});

it('processa mensagem da IA com tempo de resposta aceitável', function () {
    // Mock da resposta da IA
    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => 'Resposta da IA',
                            'action' => 'query_balance',
                        ]),
                    ],
                ],
            ],
        ], 200),
    ]);
    
    $aiService = new AIService(
        apiKey: config('ai.groq.api_key', 'test-key'),
        provider: 'groq'
    );
    
    $startTime = microtime(true);
    
    $result = $aiService->processMessage('Qual é o meu saldo?', $this->user);
    
    $executionTime = (microtime(true) - $startTime) * 1000; // em milissegundos
    
    expect($executionTime)->toBeLessThan(3000); // Deve executar em menos de 3 segundos (com mock)
    expect($result)->toHaveKey('reply');
    expect($result)->toHaveKey('action');
});

it('busca transações recentes com performance aceitável', function () {
    // Cria muitas transações
    Transaction::factory()->count(500)->create([
        'user_id' => $this->user->id,
    ]);
    
    $startTime = microtime(true);
    
    $recent = $this->repository->getRecentForUser($this->user, 15);
    
    $executionTime = (microtime(true) - $startTime) * 1000; // em milissegundos
    
    expect($executionTime)->toBeLessThan(200); // Deve executar em menos de 200ms
    expect($recent)->toHaveCount(15);
});

it('calcula agregações mensais com performance otimizada', function () {
    // Cria transações de vários meses
    for ($i = 0; $i < 6; $i++) {
        Transaction::factory()->count(50)->create([
            'user_id' => $this->user->id,
            'type' => 'income',
            'date' => now()->subMonths($i),
        ]);
        
        Transaction::factory()->count(50)->create([
            'user_id' => $this->user->id,
            'type' => 'expense',
            'date' => now()->subMonths($i),
        ]);
    }
    
    $startTime = microtime(true);
    
    $aggregates = $this->repository->getMonthlyAggregates($this->user, now());
    
    $executionTime = (microtime(true) - $startTime) * 1000; // em milissegundos
    
    expect($executionTime)->toBeLessThan(300); // Deve executar em menos de 300ms
    expect($aggregates)->toHaveKey('income');
    expect($aggregates)->toHaveKey('expense');
});

it('calcula agregações de todos os tempos com performance otimizada', function () {
    // Cria muitas transações
    Transaction::factory()->count(1000)->create([
        'user_id' => $this->user->id,
    ]);
    
    $startTime = microtime(true);
    
    $aggregates = $this->repository->getAllTimeAggregates($this->user);
    
    $executionTime = (microtime(true) - $startTime) * 1000; // em milissegundos
    
    expect($executionTime)->toBeLessThan(500); // Deve executar em menos de 500ms
    expect($aggregates)->toHaveKey('income');
    expect($aggregates)->toHaveKey('expense');
});

it('processa múltiplas consultas simultaneamente com performance aceitável', function () {
    Transaction::factory()->count(200)->create([
        'user_id' => $this->user->id,
    ]);
    
    $startTime = microtime(true);
    
    // Executa múltiplas consultas
    $totalIncome = $this->repository->getTotalIncomeAllTime($this->user);
    $totalExpenses = $this->repository->getTotalExpensesAllTime($this->user);
    $monthlyIncome = $this->repository->getMonthlyIncome($this->user, now());
    $monthlyExpenses = $this->repository->getMonthlyExpenses($this->user, now());
    $aggregates = $this->repository->getMonthlyAggregates($this->user, now());
    $allTimeAggregates = $this->repository->getAllTimeAggregates($this->user);
    
    $executionTime = (microtime(true) - $startTime) * 1000; // em milissegundos
    
    expect($executionTime)->toBeLessThan(1000); // Todas as consultas devem executar em menos de 1 segundo
    expect($totalIncome)->toBeGreaterThanOrEqual(0);
    expect($totalExpenses)->toBeGreaterThanOrEqual(0);
});
