<?php

namespace App\Services\OpenFinance;

use App\Models\OpenFinanceConnection;
use App\Models\User;

interface OpenFinanceProvider
{
    public function createConnectToken(User $user, ?string $itemId = null): array;

    public function getItem(string $itemId): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAccounts(string $itemId): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTransactions(string $accountId): array;

    public function disconnect(OpenFinanceConnection $connection): void;
}
