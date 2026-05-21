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

        return $this->buildPayload($message, $normalized);
    }

    public function parseEdit(string $message, ?string $fallbackName = null): ?array
    {
        $normalized = $this->normalize($message);

        if (! $this->looksLikeEditIntent($normalized) && ! ($fallbackName && $this->containsEditVerb($normalized))) {
            return null;
        }

        $payload = $this->buildPayload($message, $normalized, $fallbackName);

        if ($payload === null) {
            return null;
        }

        unset($payload['start_date'], $payload['auto_record'], $payload['is_active'], $payload['category_name']);

        return $payload;
    }

    public function parseCancel(string $message, ?string $fallbackName = null): ?array
    {
        $normalized = $this->normalize($message);

        if (! $this->looksLikeCancelIntent($normalized) && ! ($fallbackName && $this->containsCancelVerb($normalized))) {
            return null;
        }

        $name = $this->extractName($message) ?? $fallbackName;

        if ($name === null || $name === '') {
            return null;
        }

        return ['name' => $name];
    }

    public function looksLikeCreateIntent(string $message): bool
    {
        if (! str_contains($message, 'assinatura')
            && ! str_contains($message, 'mensalidade')
            && ! preg_match('/\b(minha|meu)\s+[\p{L}\p{N}].*\b(mensal|anual)\b/u', $message)
        ) {
            return false;
        }

        foreach (['criar', 'crie', 'nova', 'novo', 'definir', 'defina', 'cadastrar', 'cadastre'] as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return preg_match('/\bassinatura\s+[\p{L}\p{N}]/u', $message) === 1
            || preg_match('/\b(minha|meu)\s+[\p{L}\p{N}].*\b(mensal|anual)\b/u', $message) === 1;
    }

    public function looksLikeEditIntent(string $message): bool
    {
        $hasCue = str_contains($message, 'assinatura') || str_contains($message, 'mensalidade');

        if (! $hasCue) {
            return false;
        }

        foreach (['editar', 'edita', 'alterar', 'altera', 'ajustar', 'ajusta', 'mudar', 'muda', 'atualizar', 'atualiza'] as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return preg_match('/\bassinatura\s+[\p{L}\p{N}].*\b(?:para|dia|mensal|anual)\b/u', $message) === 1;
    }

    public function looksLikeCancelIntent(string $message): bool
    {
        if (! str_contains($message, 'assinatura') && ! str_contains($message, 'mensalidade')) {
            return false;
        }

        foreach (['cancelar', 'cancela', 'desativar', 'desativa', 'pausar', 'pausa', 'parar', 'para'] as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return false;
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
        foreach (['cancelar', 'cancela', 'desativar', 'desativa', 'pausar', 'pausa', 'parar', 'para'] as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function buildPayload(string $originalMessage, string $normalizedMessage, ?string $fallbackName = null): ?array
    {
        $name = $this->extractName($originalMessage) ?? $fallbackName;
        $amount = $this->extractAmount($normalizedMessage);
        $billingCycle = $this->extractBillingCycle($normalizedMessage);
        $dueDay = $this->extractDueDay($normalizedMessage);

        if ($name === null) {
            return null;
        }

        if ($amount === null && $dueDay === null && $billingCycle === null) {
            return null;
        }

        return array_filter([
            'name' => $name,
            'amount' => $amount,
            'billing_cycle' => $billingCycle,
            'due_day' => $dueDay,
            'start_date' => now()->toDateString(),
            'auto_record' => false,
            'is_active' => true,
            'category_name' => 'Assinaturas',
            'bank_account_name' => $this->extractBankAccountName($originalMessage),
            'credit_card_name' => $this->extractCreditCardName($originalMessage),
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function extractName(string $message): ?string
    {
        if (preg_match('/(?:minha|meu)\s+(.+?)\s+(?:e|é)\s+(?:mensal|anual)(?:\s+|,|$)/iu', $message, $matches)) {
            $name = trim((string) ($matches[1] ?? ''));
            $name = trim($name, " \t\n\r\0\x0B-:");

            return $name !== '' ? mb_convert_case($name, MB_CASE_TITLE, 'UTF-8') : null;
        }

        if (! preg_match('/(?:assinatura|mensalidade)\s+(.+?)(?:\s+(?:mensal|anual|dia\s+\d+|com|valor|r\$|\d|para)|[,.]|$)/iu', $message, $matches)) {
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
        if (preg_match_all('/(?:r\$\s*|valor\s+de\s+)?(\d+(?:[\.,]\d{1,2})?)\s*(?:reais?)/u', $message, $explicitMatches) && ! empty($explicitMatches[1])) {
            $raw = str_replace('.', '', end($explicitMatches[1]));
            $amount = (float) str_replace(',', '.', $raw);

            return $amount > 0 ? $amount : null;
        }

        $messageWithoutDueDay = preg_replace('/dia\s+\d{1,2}/u', '', $message) ?? $message;

        if (! preg_match_all('/(?:r\$\s*)?(\d+(?:[\.,]\d{1,2})?)/u', $messageWithoutDueDay, $matches) || empty($matches[1])) {
            return null;
        }

        $raw = str_replace('.', '', end($matches[1]));
        $amount = (float) str_replace(',', '.', $raw);

        return $amount > 0 ? $amount : null;
    }

    private function extractBillingCycle(string $message): ?string
    {
        if (str_contains($message, 'anual')) {
            return 'yearly';
        }

        if (str_contains($message, 'mensal')) {
            return 'monthly';
        }

        return null;
    }

    private function extractDueDay(string $message): ?int
    {
        if (preg_match('/dia\s+(\d{1,2})/u', $message, $matches)) {
            $day = (int) $matches[1];

            if ($day >= 1 && $day <= 31) {
                return $day;
            }
        }

        return null;
    }

    private function extractBankAccountName(string $message): ?string
    {
        if (preg_match('/(?:na conta|pela conta|via conta)\s+(.+?)(?:\s+(?:dia\s+\d+|mensal|anual|com|valor|r\$|\d)|[,.]|$)/iu', $message, $matches) !== 1) {
            return null;
        }

        $value = trim((string) ($matches[1] ?? ''));

        return $value !== '' ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : null;
    }

    private function extractCreditCardName(string $message): ?string
    {
        if (preg_match('/(?:no cart(?:a[oã]))|(?:pelo cart(?:a[oã]))|(?:via cart(?:a[oã]))/iu', $message) !== 1) {
            return null;
        }

        if (preg_match('/(?:no cart(?:a[oã])|pelo cart(?:a[oã])|via cart(?:a[oã]))\s+(.+?)(?:\s+(?:dia\s+\d+|mensal|anual|com|valor|r\$|\d)|[,.]|$)/iu', $message, $matches) !== 1) {
            return null;
        }

        $value = trim((string) ($matches[1] ?? ''));

        return $value !== '' ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : null;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $converted !== false ? $converted : $value;
    }
}
