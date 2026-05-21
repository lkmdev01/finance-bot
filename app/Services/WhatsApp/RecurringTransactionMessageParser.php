<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Str;

class RecurringTransactionMessageParser
{
    public function parse(string $message): ?array
    {
        $partial = $this->parsePartialCreate($message);

        if ($partial === null || ($partial['amount'] ?? null) === null) {
            return null;
        }

        return $partial;
    }

    public function parsePartialCreate(string $message): ?array
    {
        $normalized = $this->normalize($message);
        $sanitized = $this->sanitizePatternInput($message);

        if (! $this->looksLikeCreateIntent($normalized)) {
            return null;
        }

        $amount = $this->extractAmount($sanitized);
        $frequency = $this->extractFrequency($normalized);
        $description = $this->extractDescription($sanitized, $frequency);
        $dayOfMonth = $this->extractDayOfMonth($sanitized, $normalized);
        [$bankAccountName, $creditCardName] = $this->extractFinancialSourceNames($sanitized);
        $categoryName = $this->extractCategoryName($sanitized) ?? $description;
        $type = $this->extractType($normalized);

        if ($description === null || $frequency === null) {
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
            || str_contains($message, 'todo ms')
            || str_contains($message, 'cada mes')
            || str_contains($message, 'cada ms')
            || str_contains($message, 'mensal')
            || str_contains($message, 'toda semana')
            || str_contains($message, 'semanal');

        if (! $hasCadence) {
            return false;
        }

        return $this->containsAny($message, ['pago', 'pagar', 'gasto', 'gastar', 'recebo', 'receber', 'ganho', 'ganhar', 'debito', 'debitar'])
            || str_contains($message, 'minha ')
            || str_contains($message, 'meu ');
    }

    public function parseEdit(string $message, ?string $fallbackDescription = null): ?array
    {
        $normalized = $this->normalize($message);
        $sanitized = $this->sanitizePatternInput($message);

        if (! $this->looksLikeEditIntent($normalized) && ! ($fallbackDescription && $this->containsEditVerb($normalized))) {
            return null;
        }

        $description = $this->extractRecurringName($sanitized, $fallbackDescription);
        $amount = $this->extractAmount($sanitized);
        $frequency = $this->extractFrequency($normalized);
        $dayOfMonth = $this->extractDayOfMonth($sanitized, $normalized);
        [$bankAccountName, $creditCardName] = $this->extractFinancialSourceNames($sanitized);
        $categoryName = $this->extractCategoryName($sanitized);

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
        $sanitized = $this->sanitizePatternInput($message);

        if (! $this->looksLikeCancelIntent($normalized) && ! ($fallbackDescription && $this->containsCancelVerb($normalized))) {
            return null;
        }

        $description = $this->extractRecurringName($sanitized, $fallbackDescription);

        if ($description === null || $description === '') {
            return null;
        }

        return ['description' => $description];
    }

    public function looksLikeEditIntent(string $message): bool
    {
        return $this->hasRecurringCue($message) && $this->containsEditVerb($message);
    }

    public function looksLikeCancelIntent(string $message): bool
    {
        return $this->hasRecurringCue($message) && $this->containsCancelVerb($message);
    }

    private function extractAmount(string $message): ?float
    {
        $patterns = [
            '/(?:r\$\s*)?(\d+(?:[\.,]\d{1,2})?)\s*(?:reais?|rs)\b/iu',
            '/(?:de|por|valor(?:\\s+(?:e|=))?|o valor(?:\\s+(?:e|=))?)\\s+(?:r\\$\\s*)?(\\d+(?:[\\.,]\\d{1,2})?)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches) === 1) {
                $raw = str_replace('.', '', $matches[1]);
                $amount = (float) str_replace(',', '.', $raw);
                return $amount > 0 ? $amount : null;
            }
        }

        if (preg_match('/(?:todo dia|dia)\s+\d{1,2}\b/iu', $message) === 1) {
            return null;
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
            '/(?:todo dia\s+\d{1,2}|todo mes|todo ms|cada mes|cada ms|mensal|toda semana|semanal)\s+(?:eu\s+)?(?:pago|pagar|gasto|gastar|recebo|receber|ganho|ganhar)\s+(.+?)(?:\s+(?:r\$\s*)?\d+(?:[\.,]\d{1,2})?|\s+dia\s+\d{1,2}|[,.]|$)/iu',
            '/(?:pago|pagar|gasto|gastar|recebo|receber|ganho|ganhar)\s+(.+?)\s+(?:todo dia\s+\d{1,2}|todo mes|todo ms|cada mes|cada ms|mensal|toda semana|semanal)(?:\s|$)/iu',
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

    private function extractFrequency(string $message): ?string
    {
        if (str_contains($message, 'toda semana') || str_contains($message, 'semanal')) {
            return 'weekly';
        }

        if (str_contains($message, 'todo dia') || str_contains($message, 'todo mes') || str_contains($message, 'todo ms') || str_contains($message, 'cada mes') || str_contains($message, 'cada ms') || str_contains($message, 'mensal')) {
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

        if (str_contains($message, 'mensal') || str_contains($message, 'todo mes') || str_contains($message, 'todo ms') || str_contains($message, 'cada mes') || str_contains($message, 'cada ms')) {
            return now()->day;
        }

        return null;
    }

    private function extractType(string $message): string
    {
        return $this->containsAny($message, ['recebo', 'receber', 'ganho', 'ganhar']) ? 'income' : 'expense';
    }

    private function extractCategoryName(string $message): ?string
    {
        if (preg_match('/(?:categoria|na categoria)\s+(.+?)(?:\s+(?:na conta|no cartao|no cart[aÃ£]o|pela conta|pelo cartao|pelo cart[aÃ£]o|via conta|via cartao|via cart[aÃ£]o)|[,.]|$)/iu', $message, $matches) === 1) {
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

        if (preg_match('/(?:no cartao|no cart[aÃ£]o|pelo cartao|pelo cart[aÃ£]o|via cartao|via cart[aÃ£]o)\s+(.+?)(?:\s+(?:categoria|mensal|semanal|todo dia|todo mes|cada mes|dia\s+\d{1,2})|[,.]|$)/iu', $message, $matches) === 1) {
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
            || str_contains($message, 'todo ms')
            || str_contains($message, 'cada mes')
            || str_contains($message, 'cada ms')
            || str_contains($message, 'mensal')
            || str_contains($message, 'toda semana')
            || str_contains($message, 'semanal')
            || str_contains($message, 'recorr');
    }

    private function containsEditVerb(string $message): bool
    {
        return $this->containsAny($message, ['editar', 'edita', 'alterar', 'altera', 'ajustar', 'ajusta', 'mudar', 'muda', 'atualizar', 'atualiza']);
    }

    private function containsCancelVerb(string $message): bool
    {
        return $this->containsAny($message, ['cancelar', 'cancela', 'desativar', 'desativa', 'parar', 'para', 'pausar', 'pausa']);
    }

    private function cleanupTrailingContext(string $value, ?string $frequency): string
    {
        $value = trim($value, " \t\n\r\0\x0B-:");
        $value = preg_replace('/^(?:um|uma|meu|minha)\s+/iu', '', $value) ?? $value;
        $value = preg_replace('/\s+(?:na conta|no cartao|no cart[aÃ£]o|pela conta|pelo cartao|pelo cart[aÃ£]o|via conta|via cartao|via cart[aÃ£]o)\s+.+$/iu', '', $value) ?? $value;
        $value = preg_replace('/\s+(?:categoria|na categoria)\s+.+$/iu', '', $value) ?? $value;
        $value = preg_replace('/\b(?:mensal|semanal|todo dia|todo mes|todo ms|cada mes|cada ms|toda semana)\b/iu', '', $value) ?? $value;
        $value = preg_replace('/\bdia\s+\d{1,2}\b/iu', '', $value) ?? $value;
        $value = preg_replace('/(?:r\$\s*)?\d+(?:[\.,]\d{1,2})?/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? $value;

        if ($frequency === 'monthly' && str_ends_with(mb_strtolower($value), ' e')) {
            $value = trim(mb_substr($value, 0, -2));
        }

        return trim($value);
    }

    private function containsAny(string $message, array $terms): bool
    {
        foreach ($terms as $term) {
            if (str_contains($message, $term)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = Str::ascii($value);

        return str_replace(['?', '�'], '', $value);
    }

    private function sanitizePatternInput(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = Str::ascii($value);
        $value = str_replace(['?', '�'], '', $value);

        return $value;
    }
}
