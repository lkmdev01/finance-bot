<?php

namespace App\Services\WhatsApp;

class SubscriptionMessageParser
{
    public function parse(string $message): ?array
    {
        $normalized = $this->normalize($message);

        if (! $this->looksLikeCreateIntent($normalized)) {
            return null;
        }

        $name = $this->extractName($message);
        $amount = $this->extractAmount($normalized);
        $billingCycle = $this->extractBillingCycle($normalized);
        $dueDay = $this->extractDueDay($normalized);

        if ($name === null || $amount === null) {
            return null;
        }

        return [
            'name' => $name,
            'amount' => $amount,
            'billing_cycle' => $billingCycle,
            'due_day' => $dueDay,
            'start_date' => now()->toDateString(),
            'auto_record' => false,
            'is_active' => true,
            'category_name' => 'Assinaturas',
        ];
    }

    public function looksLikeCreateIntent(string $message): bool
    {
        if (! str_contains($message, 'assinatura') && ! str_contains($message, 'mensalidade')) {
            return false;
        }

        foreach (['criar', 'crie', 'nova', 'novo', 'definir', 'defina', 'cadastrar', 'cadastre'] as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return preg_match('/\bassinatura\s+[\p{L}\p{N}]/u', $message) === 1;
    }

    private function extractName(string $message): ?string
    {
        if (! preg_match('/assinatura\s+(.+?)(?:\s+(?:mensal|anual|dia\s+\d+|com|valor|r\$|\d)|[,.]|$)/iu', $message, $matches)) {
            return null;
        }

        $name = trim((string) ($matches[1] ?? ''));
        $name = trim($name, " \t\n\r\0\x0B-:");

        if ($name === '') {
            return null;
        }

        return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
    }

    private function extractAmount(string $message): ?float
    {
        if (! preg_match_all('/(?:r\$\s*)?(\d+(?:[\.,]\d{1,2})?)\s*(?:reais?)?/u', $message, $matches) || empty($matches[1])) {
            return null;
        }

        $raw = str_replace('.', '', end($matches[1]));
        $amount = (float) str_replace(',', '.', $raw);

        return $amount > 0 ? $amount : null;
    }

    private function extractBillingCycle(string $message): string
    {
        return str_contains($message, 'anual') ? 'yearly' : 'monthly';
    }

    private function extractDueDay(string $message): int
    {
        if (preg_match('/dia\s+(\d{1,2})/u', $message, $matches)) {
            $day = (int) $matches[1];

            if ($day >= 1 && $day <= 31) {
                return $day;
            }
        }

        return now()->day;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $converted !== false ? $converted : $value;
    }
}
