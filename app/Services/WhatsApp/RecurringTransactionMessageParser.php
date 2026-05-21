<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Str;

class RecurringTransactionMessageParser
{
    public function parse(string $message): ?array
    {
        $normalized = $this->normalize($message);

        if (! $this->looksLikeCreateIntent($normalized)) {
            return null;
        }

        $amount = $this->extractAmount($message);
        $description = $this->extractDescription($message);
        $frequency = $this->extractFrequency($normalized);
        $dayOfMonth = $this->extractDayOfMonth($normalized);
        $type = $this->extractType($normalized);

        if ($amount === null || $description === null || $frequency === null) {
            return null;
        }

        return array_filter([
            'type' => $type,
            'amount' => $amount,
            'description' => $description,
            'frequency' => $frequency,
            'start_date' => now()->toDateString(),
            'day_of_month' => $dayOfMonth,
            'category_name' => $description,
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function looksLikeCreateIntent(string $message): bool
    {
        if (! str_contains($message, 'todo dia') && ! str_contains($message, 'todo mes') && ! str_contains($message, 'todo m') && ! str_contains($message, 'toda semana')) {
            return false;
        }

        return str_contains($message, 'pago')
            || str_contains($message, 'gasto')
            || str_contains($message, 'recebo')
            || str_contains($message, 'ganho');
    }

    private function extractAmount(string $message): ?float
    {
        if (! preg_match('/(?:r\$\s*)?(\d+(?:[\.,]\d{1,2})?)/u', $message, $matches)) {
            return null;
        }

        $raw = str_replace('.', '', $matches[1]);
        $amount = (float) str_replace(',', '.', $raw);

        return $amount > 0 ? $amount : null;
    }

    private function extractDescription(string $message): ?string
    {
        if (preg_match('/(?:pago|gasto|recebo|ganho)\s+(.+?)(?:\s+(?:r\$\s*)?\d+(?:[\.,]\d{1,2})?|[,.]|$)/iu', $message, $matches)) {
            $description = trim((string) ($matches[1] ?? ''));
            $description = trim($description, " \t\n\r\0\x0B-:");

            return $description !== '' ? mb_convert_case($description, MB_CASE_TITLE, 'UTF-8') : null;
        }

        return null;
    }

    private function extractFrequency(string $message): ?string
    {
        if (str_contains($message, 'todo dia')) {
            return 'monthly';
        }

        if (str_contains($message, 'todo mes') || str_contains($message, 'todo m')) {
            return 'monthly';
        }

        if (str_contains($message, 'toda semana')) {
            return 'weekly';
        }

        return null;
    }

    private function extractDayOfMonth(string $message): ?int
    {
        if (preg_match('/todo dia\s+(\d{1,2})/u', $message, $matches) || preg_match('/dia\s+(\d{1,2})/u', $message, $matches)) {
            $day = (int) $matches[1];

            if ($day >= 1 && $day <= 31) {
                return $day;
            }
        }

        return null;
    }

    private function extractType(string $message): string
    {
        return str_contains($message, 'recebo') || str_contains($message, 'ganho')
            ? 'income'
            : 'expense';
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return Str::ascii($value);
    }
}
