<?php

use App\Models\Transaction;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

it('pode listar transações via API', function () {
    Transaction::factory()->count(5)->create([
        'user_id' => $this->user->id,
    ]);

    getJson('/api/transactions')
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'type', 'amount', 'description', 'date'],
            ],
        ]);
});

it('pode criar transação via API', function () {
    $data = [
        'type' => 'expense',
        'amount' => 100.50,
        'description' => 'Teste API',
        'date' => now()->format('Y-m-d'),
    ];

    postJson('/api/transactions', $data)
        ->assertCreated()
        ->assertJsonFragment(['description' => 'Teste API']);

    assertDatabaseHas('transactions', [
        'user_id' => $this->user->id,
        'description' => 'Teste API',
    ]);
});

it('pode atualizar transação via API', function () {
    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'amount' => 100.00,
    ]);

    putJson("/api/transactions/{$transaction->id}", [
        'amount' => 200.00,
    ])
        ->assertSuccessful()
        ->assertJsonFragment(['amount' => '200.00']);

    expect((float) $transaction->fresh()->amount)->toBe(200.0);
});

it('pode excluir transação via API', function () {
    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
    ]);

    deleteJson("/api/transactions/{$transaction->id}")
        ->assertSuccessful();

    expect(Transaction::find($transaction->id))->toBeNull();
});

it('não permite acessar transações de outros usuários', function () {
    $otherUser = User::factory()->create();
    $transaction = Transaction::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    getJson("/api/transactions/{$transaction->id}")
        ->assertForbidden();
});
