<?php

use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionDuplicateDetectionService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->duplicateService = app(TransactionDuplicateDetectionService::class);
});

it('detecta transações duplicadas', function () {
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'description' => 'Supermercado',
        'amount' => 100.00,
        'date' => now(),
        'type' => 'expense',
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'description' => 'Supermercado',
        'amount' => 100.00,
        'date' => now(),
        'type' => 'expense',
    ]);

    $duplicates = $this->duplicateService->detectDuplicates($this->user, 7);

    expect($duplicates)->not->toBeEmpty();
});

it('calcula similaridade corretamente', function () {
    $t1 = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'description' => 'Supermercado',
        'amount' => 100.00,
        'date' => now(),
        'type' => 'expense',
    ]);

    $t2 = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'description' => 'Supermercado',
        'amount' => 100.00,
        'date' => now(),
        'type' => 'expense',
    ]);

    $reflection = new \ReflectionClass($this->duplicateService);
    $method = $reflection->getMethod('calculateSimilarity');
    $method->setAccessible(true);

    $similarity = $method->invoke($this->duplicateService, $t1, $t2);

    expect($similarity)->toBeGreaterThan(80);
});

it('resolve duplicata mantendo primeira transação', function () {
    $t1 = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'description' => 'Teste',
        'amount' => 50.00,
    ]);

    $t2 = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'description' => 'Teste',
        'amount' => 50.00,
    ]);

    \App\Models\TransactionDuplicate::create([
        'user_id' => $this->user->id,
        'transaction_id' => $t1->id,
        'duplicate_transaction_id' => $t2->id,
        'similarity_score' => 95.0,
    ]);

    $this->duplicateService->resolveDuplicate(1, true);

    expect(Transaction::find($t1->id))->not->toBeNull();
    expect(Transaction::find($t2->id))->toBeNull();
});
