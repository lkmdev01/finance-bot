<?php

namespace App\Services\WhatsApp;

use Carbon\Carbon;
use Illuminate\Support\Str;

class InstallmentTransactionMessageParser
{
    public function parse(string $message): ?array
    {
        $normalized = $this->normalize($message);

        if (! $this->looksLikeCreateIntent($normalized)) {
            return null;
        }

        $installments = $this->extractInstallmentCount($normalized);
        $amountData = $this->extractAmountData($message, $installments);
        $description = $this->extractDescription($message);
        [$bankAccountName, $creditCardName] = $this->extractFinancialSourceNames($message);
        $categoryName = $this->extractCategoryName($message) ?? $description;

        if ($installments === null || $amountData === null || $description === null) {
            return null;
        }

        return array_filter([
            'type' => 'expense',
            'description' => $description,
            'total_amount' => $amountData['total_amount'],
            'installment_count' => $installments,
            'per_installment_amount' => $amountData['per_installment_amount'],
            'date' => ($this->extractDate($message) ?? now())->toDateString(),
            'category_name' => $categoryName,
            'bank_account_name' => $bankAccountName,
            'credit_card_name' => $creditCardName,
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function looksLikeCreateIntent(string $message): bool
    {
        $hasInstallments = preg_match('/\b\d{1,2}x\b/u', $message) === 1
            || preg_match('/\b\d{1,2}\s+vezes\b/u', $message) === 1
            || str_contains($message, 'parcelad')
            || str_contains($message, 'parcelar')
            || str_contains($message, 'dividido em');

        if (! $hasInstallments) {
            return false;
        }

        return str_contains($message, 'comprei')
            || str_contains($message, 'paguei')
            || str_contains($message, 'passei')
            || str_contains($message, 'parcelei');
    }

    private function extractInstallmentCount(string $message): ?int
    {
        if (
            preg_match('/\b(\d{1,2})x\b/u', $message, $matches)
            || preg_match('/\b(\d{1,2})\s+vezes\b/u', $message, $matches)
            || preg_match('/parcelad[oa]\s+em\s+(\d{1,2})\b/u', $message, $matches)
            || preg_match('/dividido\s+em\s+(\d{1,2})\b/u', $message, $matches)
        ) {
            $count = (int) $matches[1];

            return $count >= 2 ? $count : null;
        }

        return null;
    }

    private function extractAmountData(string $message, int $installments): ?array
    {
        if (preg_match('/(?:em\s+)?\d{1,2}(?:x|\s+vezes)\s+de\s+(?:r\$\s*)?(\d+(?:[\.,]\d{1,2})?)/iu', $message, $matches) === 1) {
            $raw = str_replace('.', '', $matches[1]);
            $perInstallment = (float) str_replace(',', '.', $raw);

            if ($perInstallment > 0) {
                return [
                    'total_amount' => round($perInstallment * $installments, 2),
                    'per_installment_amount' => round($perInstallment, 2),
                ];
            }
        }

        $patterns = [
            '/(?:por|de)\s+(?:r\$\s*)?(\d+(?:[\.,]\d{1,2})?)/iu',
            '/(?:r\$\s*)?(\d+(?:[\.,]\d{1,2})?)\s+(?:em\s+\d{1,2}(?:x|\s+vezes)|parcelad[oa]|dividido\s+em)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches) === 1) {
                $raw = str_replace('.', '', $matches[1]);
                $amount = (float) str_replace(',', '.', $raw);

                if ($amount > 0) {
                    return [
                        'total_amount' => round($amount, 2),
                        'per_installment_amount' => round($amount / $installments, 2),
                    ];
                }
            }
        }

        if (! preg_match_all('/(?:r\$\s*)?(\d+(?:[\.,]\d{1,2})?)/u', $message, $matches) || empty($matches[1])) {
            return null;
        }

        $raw = str_replace('.', '', $matches[1][0]);
        $amount = (float) str_replace(',', '.', $raw);

        if ($amount <= 0) {
            return null;
        }

        return [
            'total_amount' => round($amount, 2),
            'per_installment_amount' => round($amount / $installments, 2),
        ];
    }

    private function extractDescription(string $message): ?string
    {
        $patterns = [
            '/(?:comprei|paguei|passei|parcelei)\s+(.+?)(?:\s+(?:por|de|em)\s+(?:r\$\s*)?\d|\s+\d{1,2}x|\s+\d{1,2}\s+vezes|\s+parcelad[oa]|\s+dividido\s+em|[,.]|$)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches) === 1) {
                $description = trim((string) ($matches[1] ?? ''));
                $description = trim($description, " \t\n\r\0\x0B-:");
                $description = preg_replace('/^(?:um|uma)\s+/iu', '', $description) ?? $description;
                $description = preg_replace('/\\s+e\\s+a\\s+parcela\\b.*$/iu', '', $description) ?? $description;
                $description = preg_replace('/\s+(?:na conta|no cart(?:a|ã)o|pela conta|pelo cart(?:a|ã)o|via conta|via cart(?:a|ã)o|na categoria)\s+.+$/iu', '', $description) ?? $description;

                return $description !== '' ? mb_convert_case(trim($description), MB_CASE_TITLE, 'UTF-8') : null;
            }
        }

        return null;
    }

    private function extractCategoryName(string $message): ?string
    {
        if (preg_match('/(?:categoria|na categoria)\s+(.+?)(?:\s+(?:na conta|no cart(?:a|ã)o|pela conta|pelo cart(?:a|ã)o)|[,.]|$)/iu', $message, $matches) === 1) {
            $category = trim((string) ($matches[1] ?? ''));
            return $category !== '' ? mb_convert_case($category, MB_CASE_TITLE, 'UTF-8') : null;
        }

        return null;
    }

    private function extractFinancialSourceNames(string $message): array
    {
        $bankAccountName = null;
        $creditCardName = null;

        if (preg_match('/(?:na conta|pela conta|via conta)\s+(.+?)(?:\s+(?:categoria|parcelad[oa]|em\s+\d{1,2}(?:x|\s+vezes))|[,.]|$)/iu', $message, $matches) === 1) {
            $bankAccountName = trim((string) ($matches[1] ?? ''));
        }

        if (preg_match('/(?:no cart(?:a|ã)o|pelo cart(?:a|ã)o|via cart(?:a|ã)o)\s+(.+?)(?:\s+(?:categoria|parcelad[oa]|em\s+\d{1,2}(?:x|\s+vezes))|[,.]|$)/iu', $message, $matches) === 1) {
            $creditCardName = trim((string) ($matches[1] ?? ''));
        }

        return [$bankAccountName ?: null, $creditCardName ?: null];
    }

    private function extractDate(string $message): ?Carbon
    {
        $normalized = $this->normalize($message);

        if (str_contains($normalized, 'ontem')) {
            return now()->subDay();
        }

        if (str_contains($normalized, 'hoje')) {
            return now();
        }

        if (preg_match('/\b(\d{1,2})\/(\d{1,2})\/(\d{2,4})\b/u', $message, $matches) === 1) {
            $day = (int) $matches[1];
            $month = (int) $matches[2];
            $year = (int) $matches[3];

            if ($year < 100) {
                $year += 2000;
            }

            return Carbon::create($year, $month, $day);
        }

        return null;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return Str::ascii($value);
    }
}
