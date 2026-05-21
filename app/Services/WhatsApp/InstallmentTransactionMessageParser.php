<?php

namespace App\Services\WhatsApp;

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
        $amount = $this->extractAmount($message);
        $description = $this->extractDescription($message);

        if ($installments === null || $amount === null || $description === null) {
            return null;
        }

        return [
            'type' => 'expense',
            'description' => $description,
            'total_amount' => $amount,
            'installment_count' => $installments,
            'per_installment_amount' => round($amount / $installments, 2),
            'date' => now()->toDateString(),
            'category_name' => $description,
        ];
    }

    public function looksLikeCreateIntent(string $message): bool
    {
        return preg_match('/\b\d{1,2}x\b/u', $message) === 1
            && (str_contains($message, 'comprei') || str_contains($message, 'paguei'));
    }

    private function extractInstallmentCount(string $message): ?int
    {
        if (preg_match('/\b(\d{1,2})x\b/u', $message, $matches)) {
            $count = (int) $matches[1];

            return $count >= 2 ? $count : null;
        }

        return null;
    }

    private function extractAmount(string $message): ?float
    {
        if (! preg_match_all('/(?:r\$\s*)?(\d+(?:[\.,]\d{1,2})?)/u', $message, $matches) || empty($matches[1])) {
            return null;
        }

        $raw = str_replace('.', '', end($matches[1]));
        $amount = (float) str_replace(',', '.', $raw);

        return $amount > 0 ? $amount : null;
    }

    private function extractDescription(string $message): ?string
    {
        if (preg_match('/(?:comprei|paguei)\s+(.+?)(?:\s+(?:por|de|em)\s+(?:r\$\s*)?\d|\s+\d{1,2}x|[,.]|$)/iu', $message, $matches)) {
            $description = trim((string) ($matches[1] ?? ''));
            $description = trim($description, " \t\n\r\0\x0B-:");

            return $description !== '' ? mb_convert_case($description, MB_CASE_TITLE, 'UTF-8') : null;
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
