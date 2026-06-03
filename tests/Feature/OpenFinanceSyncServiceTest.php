<?php

use App\Models\OpenFinanceConnection;
use App\Models\User;
use App\Services\CategoryRecognitionService;
use App\Services\OpenFinance\OpenFinanceManager;
use App\Services\OpenFinance\OpenFinanceSyncService;
use App\Services\OpenFinance\PluggyService;

it('sincroniza contas cartoes e transacoes do open finance', function () {
    $user = User::factory()->create();

    $connection = OpenFinanceConnection::create([
        'user_id' => $user->id,
        'provider' => 'pluggy',
        'item_id' => 'item-123',
        'connected_at' => now(),
    ]);

    $pluggy = Mockery::mock(PluggyService::class);
    $pluggy->shouldReceive('getItem')->once()->with('item-123')->andReturn([
        'id' => 'item-123',
        'status' => 'UPDATED',
        'executionStatus' => 'SUCCESS',
        'connector' => [
            'id' => 601,
            'name' => 'Itau Open Finance',
        ],
    ]);
    $pluggy->shouldReceive('getAccounts')->once()->with('item-123')->andReturn([
        [
            'id' => 'acc-bank',
            'type' => 'BANK',
            'subtype' => 'CHECKING_ACCOUNT',
            'name' => 'Conta Corrente',
            'marketingName' => 'Conta Principal',
            'balance' => 1450.78,
            'currencyCode' => 'BRL',
        ],
        [
            'id' => 'acc-card',
            'type' => 'CREDIT',
            'subtype' => 'CREDIT_CARD',
            'name' => 'Cartao Platinum',
            'marketingName' => 'Visa Platinum',
            'number' => '1234',
            'balance' => 420.55,
            'creditData' => [
                'brand' => 'VISA',
                'creditLimit' => 5000,
                'availableCreditLimit' => 4579.45,
                'balanceCloseDate' => '2026-06-08',
                'balanceDueDate' => '2026-06-17',
                'status' => 'ACTIVE',
            ],
        ],
    ]);
    $pluggy->shouldReceive('getTransactions')->once()->with('acc-bank')->andReturn([
        [
            'id' => 'tx-income',
            'date' => now()->subDays(2)->toIso8601String(),
            'description' => 'Salario empresa',
            'amount' => 3500,
            'type' => 'CREDIT',
            'status' => 'POSTED',
        ],
    ]);
    $pluggy->shouldReceive('getTransactions')->once()->with('acc-card')->andReturn([
        [
            'id' => 'tx-card',
            'date' => now()->subDay()->toIso8601String(),
            'description' => 'Mercado bairro',
            'amount' => 120.90,
            'type' => 'DEBIT',
            'status' => 'POSTED',
            'creditCardMetadata' => [
                'installmentNumber' => 1,
                'totalInstallments' => 1,
            ],
        ],
    ]);

    $service = new OpenFinanceSyncService(
        new OpenFinanceManager($pluggy),
        app(CategoryRecognitionService::class),
    );

    $summary = $service->syncConnection($connection->fresh());

    expect($summary)->toBe([
        'accounts' => 1,
        'cards' => 1,
        'transactions' => 2,
    ]);

    $this->assertDatabaseHas('bank_accounts', [
        'user_id' => $user->id,
        'open_finance_account_id' => 'acc-bank',
        'institution' => 'Itau Open Finance',
    ]);

    $this->assertDatabaseHas('credit_cards', [
        'user_id' => $user->id,
        'open_finance_account_id' => 'acc-card',
        'issuer' => 'Itau Open Finance',
        'brand' => 'VISA',
    ]);

    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'open_finance_transaction_id' => 'tx-income',
        'type' => 'income',
        'open_finance_account_id' => 'acc-bank',
    ]);

    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'open_finance_transaction_id' => 'tx-card',
        'type' => 'expense',
        'open_finance_account_id' => 'acc-card',
    ]);

    expect($connection->fresh()->connector_name)->toBe('Itau Open Finance')
        ->and($connection->fresh()->last_sync_summary['transactions'])->toBe(2)
        ->and($connection->fresh()->sync_error)->toBeNull();
});

it('guarda o erro de sincronizacao quando o provedor falha', function () {
    $user = User::factory()->create();

    $connection = OpenFinanceConnection::create([
        'user_id' => $user->id,
        'provider' => 'pluggy',
        'item_id' => 'item-error',
        'connected_at' => now(),
    ]);

    $pluggy = Mockery::mock(PluggyService::class);
    $pluggy->shouldReceive('getItem')->once()->with('item-error')->andThrow(new RuntimeException('Falha remota de teste'));

    $service = new OpenFinanceSyncService(
        new OpenFinanceManager($pluggy),
        app(CategoryRecognitionService::class),
    );

    expect(fn () => $service->syncConnection($connection->fresh()))
        ->toThrow(RuntimeException::class, 'Falha remota de teste');

    expect($connection->fresh()->sync_error)->toBe('Falha remota de teste');
});
