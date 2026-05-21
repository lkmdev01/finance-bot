<?php

namespace App\Services\WhatsApp;

use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\User;
use Illuminate\Support\Str;

class FinancialSourceResolver
{
    public function resolve(User $user, array $data): array
    {
        $bankAccount = null;
        $creditCard = null;

        if (! empty($data['bank_account_id'])) {
            $bankAccount = $user->bankAccounts()->whereKey($data['bank_account_id'])->first();
        }

        if (! empty($data['credit_card_id'])) {
            $creditCard = $user->creditCards()->whereKey($data['credit_card_id'])->first();
        }

        if (! $bankAccount && ! empty($data['bank_account_name'])) {
            $bankAccount = $this->findBankAccountByName($user, $data['bank_account_name']);
        }

        if (! $creditCard && ! empty($data['credit_card_name'])) {
            $creditCard = $this->findCreditCardByName($user, $data['credit_card_name']);
        }

        return [$bankAccount, $creditCard];
    }

    public function findBankAccountByName(User $user, string $name): ?BankAccount
    {
        $normalized = $this->normalize($name);

        return $user->bankAccounts()
            ->get()
            ->first(function (BankAccount $account) use ($normalized) {
                $candidate = $this->normalize($account->name.' '.$account->institution);

                return $candidate === $normalized
                    || str_contains($candidate, $normalized)
                    || str_contains($normalized, $candidate);
            });
    }

    public function findCreditCardByName(User $user, string $name): ?CreditCard
    {
        $normalized = $this->normalize($name);

        return $user->creditCards()
            ->get()
            ->first(function (CreditCard $card) use ($normalized) {
                $candidate = $this->normalize(implode(' ', array_filter([
                    $card->name,
                    $card->issuer,
                    $card->brand,
                    $card->last_four ? 'final '.$card->last_four : null,
                ])));

                return $candidate === $normalized
                    || str_contains($candidate, $normalized)
                    || str_contains($normalized, $candidate);
            });
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return Str::ascii($value);
    }
}
