<?php

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;

it('processa assinaturas vencidas com auto registro', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
    ]);

    $bankAccount = BankAccount::create([
        'user_id' => $user->id,
        'name' => 'Conta principal',
        'type' => 'checking',
        'opening_balance' => 1000,
        'currency' => 'BRL',
        'is_active' => true,
    ]);

    $subscription = Subscription::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'bank_account_id' => $bankAccount->id,
        'name' => 'Spotify',
        'amount' => 29.90,
        'billing_cycle' => 'monthly',
        'due_day' => now()->day,
        'start_date' => now()->subMonth()->toDateString(),
        'next_due_date' => now()->toDateString(),
        'auto_record' => true,
        'is_active' => true,
    ]);

    $this->artisan('subscriptions:process-due')
        ->assertSuccessful();

    expect(Transaction::where('subscription_id', $subscription->id)->count())->toBe(1);
    expect($subscription->fresh()->last_paid_at?->toDateString())->toBe(now()->toDateString());
});
