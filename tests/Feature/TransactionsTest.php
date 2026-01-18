<?php

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->category = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
    ]);
});

it('pode listar transações', function () {
    Transaction::factory()->count(5)->create([
        'user_id' => $this->user->id,
    ]);

    actingAs($this->user)
        ->get(route('transactions.index'))
        ->assertSuccessful()
        ->assertSee('Transações');
});

it('pode criar uma transação', function () {
    actingAs($this->user)
        ->get(route('transactions.create'))
        ->assertSuccessful();

    $transactionData = [
        'type' => 'expense',
        'amount' => '100.50',
        'description' => 'Teste de despesa',
        'date' => now()->format('Y-m-d'),
        'category_id' => $this->category->id,
    ];

    actingAs($this->user)
        ->post(route('livewire.update'), [
            'components' => [
                [
                    'snapshot' => [
                        'data' => $transactionData,
                    ],
                    'calls' => [
                        [
                            'method' => 'save',
                            'params' => [],
                        ],
                    ],
                ],
            ],
        ]);

    assertDatabaseHas('transactions', [
        'user_id' => $this->user->id,
        'type' => 'expense',
        'amount' => 100.50,
        'description' => 'Teste de despesa',
    ]);
});

it('pode editar uma transação', function () {
    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'amount' => 50.00,
    ]);

    actingAs($this->user)
        ->get(route('transactions.edit', $transaction))
        ->assertSuccessful();

    $transaction->update([
        'amount' => 75.00,
        'description' => 'Descrição atualizada',
    ]);

    assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'amount' => 75.00,
        'description' => 'Descrição atualizada',
    ]);
});

it('pode excluir uma transação', function () {
    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $transaction->delete();

    assertDatabaseMissing('transactions', [
        'id' => $transaction->id,
    ]);
});

it('valida campos obrigatórios ao criar transação', function () {
    actingAs($this->user)
        ->post(route('livewire.update'), [
            'components' => [
                [
                    'snapshot' => [
                        'data' => [
                            'type' => '',
                            'amount' => '',
                        ],
                    ],
                    'calls' => [
                        [
                            'method' => 'save',
                            'params' => [],
                        ],
                    ],
                ],
            ],
        ]);

    // A validação deve falhar
    assertDatabaseMissing('transactions', [
        'user_id' => $this->user->id,
    ]);
});

it('filtra transações por tipo', function () {
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'amount' => 100.00,
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'amount' => 50.00,
    ]);

    actingAs($this->user)
        ->get(route('transactions.index') . '?type=income')
        ->assertSuccessful()
        ->assertSee('100.00')
        ->assertDontSee('50.00');
});
