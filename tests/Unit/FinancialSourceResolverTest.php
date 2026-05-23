<?php

namespace Tests\Unit;

use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\User;
use App\Services\WhatsApp\FinancialSourceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialSourceResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_default_credit_card_when_requested(): void
    {
        $user = User::factory()->create();

        CreditCard::create([
            'user_id' => $user->id,
            'name' => 'Nubank',
            'issuer' => 'Nubank',
            'brand' => 'Visa',
            'last_four' => '1234',
            'credit_limit' => 1000,
            'opening_balance' => 0,
            'closing_day' => 5,
            'due_day' => 25,
            'is_active' => true,
        ]);

        $resolver = new FinancialSourceResolver();

        [$bankAccount, $creditCard] = $resolver->resolve($user, [
            'payment_method' => 'credit',
            'use_default_card' => true,
        ]);

        $this->assertNull($bankAccount);
        $this->assertNotNull($creditCard);
        $this->assertSame('Nubank', $creditCard->name);
    }

    public function test_it_prefers_cash_account_when_source_is_unspecified(): void
    {
        $user = User::factory()->create();

        BankAccount::create([
            'user_id' => $user->id,
            'name' => 'Caixa',
            'institution' => 'Dinheiro',
            'type' => 'cash',
            'opening_balance' => 0,
            'currency' => 'BRL',
            'color' => '#000000',
            'is_active' => true,
        ]);

        BankAccount::create([
            'user_id' => $user->id,
            'name' => 'Conta Corrente',
            'institution' => 'Banco XYZ',
            'type' => 'checking',
            'opening_balance' => 0,
            'currency' => 'BRL',
            'color' => '#FFFFFF',
            'is_active' => true,
        ]);

        $resolver = new FinancialSourceResolver();

        [$bankAccount, $creditCard] = $resolver->resolve($user, [
            'payment_method' => 'debit',
        ]);

        $this->assertNotNull($bankAccount);
        $this->assertSame('cash', $bankAccount->type);
        $this->assertNull($creditCard);
    }
}
