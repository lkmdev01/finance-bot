<?php

use App\Models\Transaction;
use App\Models\User;
use Livewire\Volt\Volt;

it('can render', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
        'amount' => 10,
        'description' => 'Teste',
        'date' => now(),
    ]);

    $component = Volt::test('transactions.edit', ['transaction' => $transaction]);

    $component->assertSee('');
});
