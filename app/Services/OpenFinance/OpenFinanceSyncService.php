<?php

namespace App\Services\OpenFinance;

use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\OpenFinanceConnection;
use App\Models\Transaction;
use App\Services\CategoryRecognitionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class OpenFinanceSyncService
{
    public function __construct(
        private readonly OpenFinanceManager $manager,
        private readonly CategoryRecognitionService $categories,
    ) {}

    /**
     * @return array{accounts:int,cards:int,transactions:int}
     */
    public function syncConnection(OpenFinanceConnection $connection): array
    {
        try {
            $provider = $this->manager->provider($connection->provider);
            $item = $provider->getItem($connection->item_id);
            $accounts = $provider->getAccounts($connection->item_id);

            $summary = [
                'accounts' => 0,
                'cards' => 0,
                'transactions' => 0,
            ];

            foreach ($accounts as $accountData) {
                $type = Str::upper((string) ($accountData['type'] ?? ''));

                if ($type === 'CREDIT') {
                    $creditCard = $this->syncCreditCard($connection, $item, $accountData);
                    $summary['cards']++;
                    $summary['transactions'] += $this->syncTransactions($connection, $accountData, null, $creditCard);

                    continue;
                }

                $bankAccount = $this->syncBankAccount($connection, $item, $accountData);
                $summary['accounts']++;
                $summary['transactions'] += $this->syncTransactions($connection, $accountData, $bankAccount, null);
            }

            $connection->forceFill([
                'connector_id' => data_get($item, 'connector.id'),
                'connector_name' => data_get($item, 'connector.name'),
                'status' => (string) ($item['status'] ?? $connection->status),
                'execution_status' => (string) ($item['executionStatus'] ?? $connection->execution_status),
                'last_sync_summary' => $summary,
                'sync_error' => null,
                'connected_at' => $connection->connected_at ?: now(),
                'last_synced_at' => now(),
                'metadata' => array_merge($connection->metadata ?? [], [
                    'item' => [
                        'id' => $item['id'] ?? $connection->item_id,
                        'status' => $item['status'] ?? null,
                        'executionStatus' => $item['executionStatus'] ?? null,
                    ],
                ]),
            ])->save();

            return $summary;
        } catch (\Throwable $exception) {
            $connection->forceFill([
                'sync_error' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }
    }

    private function syncBankAccount(OpenFinanceConnection $connection, array $item, array $accountData): BankAccount
    {
        $account = BankAccount::updateOrCreate(
            [
                'open_finance_provider' => $connection->provider,
                'open_finance_account_id' => (string) $accountData['id'],
            ],
            [
                'user_id' => $connection->user_id,
                'open_finance_connection_id' => $connection->id,
                'name' => (string) ($accountData['marketingName'] ?? $accountData['name'] ?? 'Conta Open Finance'),
                'institution' => (string) (data_get($item, 'connector.name') ?? $accountData['owner'] ?? 'Open Finance'),
                'type' => $this->mapBankSubtype((string) ($accountData['subtype'] ?? '')),
                'currency' => (string) ($accountData['currencyCode'] ?? 'BRL'),
                'is_active' => true,
                'open_finance_balance' => $this->toDecimal($accountData['balance'] ?? null),
                'open_finance_synced_at' => now(),
            ],
        );

        return $account;
    }

    private function syncCreditCard(OpenFinanceConnection $connection, array $item, array $accountData): CreditCard
    {
        $creditData = (array) ($accountData['creditData'] ?? []);

        $card = CreditCard::updateOrCreate(
            [
                'open_finance_provider' => $connection->provider,
                'open_finance_account_id' => (string) $accountData['id'],
            ],
            [
                'user_id' => $connection->user_id,
                'open_finance_connection_id' => $connection->id,
                'name' => (string) ($accountData['marketingName'] ?? $accountData['name'] ?? 'Cartao Open Finance'),
                'issuer' => (string) (data_get($item, 'connector.name') ?? 'Open Finance'),
                'brand' => (string) ($creditData['brand'] ?? ''),
                'last_four' => $this->lastFour($accountData['number'] ?? null),
                'credit_limit' => $this->toDecimal($creditData['creditLimit'] ?? null) ?? 0,
                'closing_day' => $this->extractDay($creditData['balanceCloseDate'] ?? null),
                'due_day' => $this->extractDay($creditData['balanceDueDate'] ?? null),
                'is_active' => ($creditData['status'] ?? 'ACTIVE') !== 'CANCELLED',
                'open_finance_balance' => $this->toDecimal($accountData['balance'] ?? null),
                'open_finance_available_limit' => $this->toDecimal($creditData['availableCreditLimit'] ?? null),
                'open_finance_synced_at' => now(),
            ],
        );

        return $card;
    }

    private function syncTransactions(
        OpenFinanceConnection $connection,
        array $accountData,
        ?BankAccount $bankAccount,
        ?CreditCard $creditCard,
    ): int {
        $transactions = $this->manager->provider($connection->provider)->getTransactions((string) $accountData['id']);
        $cutoff = CarbonImmutable::now()->subDays((int) config('openfinance.default_sync_days', 90))->startOfDay();
        $imported = 0;

        foreach ($transactions as $transactionData) {
            $date = Carbon::parse((string) ($transactionData['date'] ?? now()));
            if ($date->lt($cutoff)) {
                continue;
            }

            $description = trim((string) ($transactionData['descriptionRaw'] ?? $transactionData['description'] ?? 'Transacao Open Finance'));
            $amount = abs((float) ($transactionData['amount'] ?? 0));
            $type = Str::upper((string) ($transactionData['type'] ?? 'DEBIT')) === 'CREDIT' ? 'income' : 'expense';
            $transactionId = (string) ($transactionData['id'] ?? '');

            if ($transactionId === '') {
                $transactionId = sha1(implode('|', [
                    $connection->provider,
                    $accountData['id'] ?? '',
                    $date->toDateString(),
                    $description,
                    number_format($amount, 2, '.', ''),
                    $type,
                ]));
            }

            $category = $this->categories->recognizeCategory($connection->user, $description, $amount);

            Transaction::updateOrCreate(
                [
                    'open_finance_provider' => $connection->provider,
                    'open_finance_transaction_id' => $transactionId,
                ],
                [
                    'user_id' => $connection->user_id,
                    'category_id' => $category?->id,
                    'bank_account_id' => $bankAccount?->id,
                    'credit_card_id' => $creditCard?->id,
                    'open_finance_connection_id' => $connection->id,
                    'open_finance_account_id' => (string) $accountData['id'],
                    'type' => $type,
                    'amount' => $amount,
                    'description' => $description,
                    'date' => $date->toDateString(),
                    'metadata' => [
                        'source' => 'open_finance',
                        'provider' => $connection->provider,
                        'status' => $transactionData['status'] ?? null,
                        'provider_id' => $transactionData['providerId'] ?? null,
                        'provider_code' => $transactionData['providerCode'] ?? null,
                        'payment_method' => $transactionData['paymentMethod'] ?? null,
                        'merchant' => $transactionData['merchant'] ?? null,
                        'credit_card_metadata' => $transactionData['creditCardMetadata'] ?? null,
                        'raw_account_type' => $accountData['type'] ?? null,
                    ],
                ],
            );

            $imported++;
        }

        return $imported;
    }

    private function mapBankSubtype(string $subtype): string
    {
        return match (Str::upper($subtype)) {
            'SAVINGS_ACCOUNT' => 'savings',
            default => 'checking',
        };
    }

    private function extractDay(mixed $date): ?int
    {
        if (! is_string($date) || $date === '') {
            return null;
        }

        return Carbon::parse($date)->day;
    }

    private function lastFour(mixed $number): ?string
    {
        if (! is_string($number) && ! is_numeric($number)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $number) ?: '';

        return $digits === '' ? null : substr($digits, -4);
    }

    private function toDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }
}
