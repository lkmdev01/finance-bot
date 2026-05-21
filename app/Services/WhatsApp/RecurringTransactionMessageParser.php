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
        $frequency = $this->extractFrequency($normalized);
        $description = $this->extractDescription($message, $frequency);
        $dayOfMonth = $this->extractDayOfMonth($message, $normalized);
        [$bankAccountName, $creditCardName] = $this->extractFinancialSourceNames($message);
        $categoryName = $this->extractCategoryName($message) ?? $description;
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
            'category_name' => $categoryName,
            'bank_account_name' => $bankAccountName,
            'credit_card_name' => $creditCardName,
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function looksLikeCreateIntent(string $message): bool
    {
        $hasCadence = str_contains($message, 'todo dia')
            || str_contains($message, 'todo mes')
            || str_contains($message, 'cada mes')
            || str_contains($message, 'mensal')
            || str_contains($message, 'toda semana')
            || str_contains($message, 'semanal');

        if (! $hasCadence) {
            return false;
        }

        return str_contains($message, 'pago')
            || str_contains($message, 'gasto')
            || str_contains($message, 'recebo')
            || str_contains($message, 'ganho')
            || str_contains($message, 'minha ')
            || str_contains($message, 'meu ');
    }

    public function parseEdit(string $message, ?string $fallbackDescription = null): ?array
    {
        $normalized = $this->normalize($message);

        if (! $this->looksLikeEditIntent($normalized) && ! ($fallbackDescription && $this->containsEditVerb($normalized))) {
            return null;
        }

        $description = $this->extractRecurringName($message, $fallbackDescription);
        $amount = $this->extractAmount($message);
        $frequency = $this->extractFrequency($normalized);
        $dayOfMonth = $this->extractDayOfMonth($message, $normalized);
        [$bankAccountName, $creditCardName] = $this->extractFinancialSourceNames($message);
        $categoryName = $this->extractCategoryName($message);

        if ($description === null || ($amount === null && $frequency === null && $dayOfMonth === null && $bankAccountName === null && $creditCardName === null && $categoryName === null)) {
            return null;
        }

        return array_filter([
            'description' => $description,
            'amount' => $amount,
            'frequency' => $frequency,
            'day_of_month' => $dayOfMonth,
            'category_name' => $categoryName,
            'bank_account_name' => $bankAccountName,
            'credit_card_name' => $creditCardName,
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function parseCancel(string $message, ?string $fallbackDescription = null): ?array
    {
        $normalized = $this->normalize($message);

        if (! $this->looksLikeCancelIntent($normalized) && ! ($fallbackDescription && $this->containsCancelVerb($normalized))) {
            return null;
        }

        $description = $this->extractRecurringName($message, $fallbackDescription);

        if ($description === null || $description === '') {
            return null;
        }

        return ['description' => $description];
    }

    private function extractAmount(string $message): ?float
    {
        $patterns = [
            '/(?:r\$\s*)?(\d+(?:[\.,]\d{1,2})?)\s*(?:reais?|rs)\b/iu',
            '/(?:de|por)\s+(?:r\$\s*)?(\d+(?:[\.,]\d{1,2})?)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches) === 1) {
                $raw = str_replace('.', '', $matches[1]);
                $amount = (float) str_replace(',', '.', $raw);

                return $amount > 0 ? $amount : null;
            }
        }

        if (! preg_match_all('/(?:r\$\s*)?(\d+(?:[\.,]\d{1,2})?)/u', $message, $matches) || empty($matches[1])) {
            return null;
        }

        $raw = str_replace('.', '', end($matches[1]));
        $amount = (float) str_replace(',', '.', $raw);

        return $amount > 0 ? $amount : null;
    }

    private function extractDescription(string $message, ?string $frequency): ?string
    {
        $patterns = [
            '/(?:todo dia\s+\d{1,2}|todo mes|cada mes|mensal|toda semana|semanal)\s+(?:eu\s+)?(?:pago|gasto|recebo|ganho)\s+(.+?)(?:\s+(?:r\$\s*)?\d+(?:[\.,]\d{1,2})?|[,.]|$)/iu',
            '/(?:pago|gasto|recebo|ganho)\s+(.+?)\s+(?:todo dia\s+\d{1,2}|todo mes|cada mes|mensal|toda semana|semanal)(?:\s|$)/iu',
            '/(?:minha|meu)\s+(.+?)\s+e\s+(?:mensal|semanal)(?:\s*,?\s*dia\s+\d{1,2})?(?:\s*,?\s*(?:r\$\s*)?\d+(?:[\.,]\d{1,2})?)?/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches) === 1) {
                $description = trim((string) ($matches[1] ?? ''));
                $description = $this->cleanupTrailingContext($description, $frequency);

                if ($description !== '') {
                    return mb_convert_case($description, MB_CASE_TITLE, 'UTF-8');
                }
            }
        }

        return null;
    }

    public function looksLikeEditIntent(string $message): bool
    {
        if (! $this->hasRecurringCue($message)) {
            return false;
        }

        return $this->containsEditVerb($message);
    }

    public function looksLikeCancelIntent(string $message): bool
    {
        if (! $this->hasRecurringCue($message)) {
            return false;
        }

        return $this->containsCancelVerb($message);
    }

    private function extractFrequency(string $message): ?string
    {
        if (str_contains($message, 'toda semana') || str_contains($message, 'semanal')) {
            return 'weekly';
        }

        if (str_contains($message, 'todo dia') || str_contains($message, 'todo mes') || str_contains($message, 'cada mes') || str_contains($message, 'mensal')) {
            return 'monthly';
        }

        return null;
    }

    private function extractDayOfMonth(string $originalMessage, string $message): ?int
    {
        if (preg_match('/(?:todo dia|dia)\s+(\d{1,2})/u', $originalMessage, $matches) === 1) {
            $day = (int) $matches[1];

            return ($day >= 1 && $day <= 31) ? $day : null;
        }

        if (str_contains($message, 'mensal') || str_contains($message, 'todo mes') || str_contains($message, 'cada mes')) {
            return now()->day;
        }

        return null;
    }

    private function extractType(string $message): string
    {
        return str_contains($message, 'recebo') || str_contains($message, 'ganho')
            ? 'income'
            : 'expense';
    }

    private function extractCategoryName(string $message): ?string
    {
        if (preg_match('/(?:categoria|na categoria)\s+(.+?)(?:\s+(?:na conta|no cartao|no cart[aã]o|pela conta|pelo cartao|pelo cart[aã]o|via conta|via cartao|via cart[aã]o)|[,.]|$)/iu', $message, $matches) === 1) {
            $category = trim((string) ($matches[1] ?? ''));
            $category = $this->cleanupTrailingContext($category, null);

            return $category !== '' ? mb_convert_case($category, MB_CASE_TITLE, 'UTF-8') : null;
        }

        return null;
    }

    private function extractFinancialSourceNames(string $message): array
    {
        $bankAccountName = null;
        $creditCardName = null;

        if (preg_match('/(?:na conta|pela conta|via conta)\s+(.+?)(?:\s+(?:categoria|mensal|semanal|todo dia|todo mes|cada mes|dia\s+\d{1,2})|[,.]|$)/iu', $message, $matches) === 1) {
            $bankAccountName = $this->cleanupTrailingContext(trim((string) ($matches[1] ?? '')), null);
        }

        if (preg_match('/(?:no cartao|no cart[aã]o|pelo cartao|pelo cart[aã]o|via cartao|via cart[aã]o)\s+(.+?)(?:\s+(?:categoria|mensal|semanal|todo dia|todo mes|cada mes|dia\s+\d{1,2})|[,.]|$)/iu', $message, $matches) === 1) {
            $creditCardName = $this->cleanupTrailingContext(trim((string) ($matches[1] ?? '')), null);
        }

        return [$bankAccountName ?: null, $creditCardName ?: null];
    }

    private function extractRecurringName(string $message, ?string $fallbackDescription): ?string
    {
        if ($fallbackDescription !== null && $fallbackDescription !== '' && preg_match('/\b(ajusta|ajustar|editar|edita|mudar|muda|alterar|altera|cancelar|cancela|pausar|pausa|parar|para)\b/iu', $message) === 1) {
            return $fallbackDescription;
        }

        return $this->extractDescription($message, null) ?? $fallbackDescription;
    }

    private function hasRecurringCue(string $message): bool
    {
        return str_contains($message, 'todo dia')
            || str_contains($message, 'todo mes')
            || str_contains($message, 'cada mes')
            || str_contains($message, 'mensal')
            || str_contains($message, 'toda semana')
            || str_contains($message, 'semanal')
            || str_contains($message, 'recorr');
    }

    private function containsEditVerb(string $message): bool
    {
        foreach (['editar', 'edita', 'alterar', 'altera', 'ajustar', 'ajusta', 'mudar', 'muda', 'atualizar', 'atualiza'] as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function containsCancelVerb(string $message): bool
    {
        foreach (['cancelar', 'cancela', 'desativar', 'desativa', 'parar', 'para', 'pausar', 'pausa'] as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function cleanupTrailingContext(string $value, ?string $frequency): string
    {
        $value = trim($value, " \t\n\r\0\x0B-:");
        $value = preg_replace('/^(?:um|uma|meu|minha)\s+/iu', '', $value) ?? $value;
        $value = preg_replace('/\s+(?:na conta|no cartao|no cart[aã]o|pela conta|pelo cartao|pelo cart[aã]o|via conta|via cartao|via cart[aã]o)\s+.+$/iu', '', $value) ?? $value;
        $value = preg_replace('/\s+(?:categoria|na categoria)\s+.+$/iu', '', $value) ?? $value;
        $value = preg_replace('/\b(?:mensal|semanal|todo dia|todo mes|cada mes|toda semana)\b/iu', '', $value) ?? $value;
        $value = preg_replace('/\bdia\s+\d{1,2}\b/iu', '', $value) ?? $value;
        $value = preg_replace('/(?:r\$\s*)?\d+(?:[\.,]\d{1,2})?/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? $value;

        if ($frequency === 'monthly' && str_ends_with(mb_strtolower($value), ' e')) {
            $value = trim(mb_substr($value, 0, -2));
        }

        return trim($value);
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return Str::ascii($value);
    }
}
