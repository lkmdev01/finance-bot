<?php

namespace App\Services\WhatsApp;

use App\Services\WhatsApp\Support\NormalizesWhatsAppText;
use App\Services\WhatsApp\IncomingMessageNormalizer;
use Carbon\Carbon;

class SimpleTransactionMessageParser
{
    use NormalizesWhatsAppText;

    public function looksLikeCreateIntent(string $normalizedMessage): bool
    {
        if (! $this->containsAnyText($normalizedMessage, [
            'recebi', 'ganhei', 'ganho', 'entrou', 'tive um ganho',
            'gastei', 'paguei', 'comprei', 'tive um gasto',
        ])) {
            return false;
        }

        return preg_match('/(?:r\\$\\s*)?\\d+(?:[\\.,]\\d{1,2})?/u', $normalizedMessage) === 1;
    }

    public function parse(string $message): ?array
    {
        if (! preg_match('/(?:r\\$\\s*)?(\\d+(?:[\\.,]\\d{1,2})?)/u', $message, $amountMatches)) {
            return null;
        }

        $amount = (float) str_replace(',', '.', str_replace('.', '', $amountMatches[1]));

        if ($amount <= 0) {
            return null;
        }

        $normalized = $this->normalizeText($message);
        $type = $this->inferType($normalized);

        if ($type === null) {
            return null;
        }

        $description = $this->extractDescription($message, $type);
        $paymentMethod = $this->extractPaymentMethod($normalized);
        $date = $this->extractDate($message, $normalized);
        $categoryName = $this->extractCategoryName($message);
        [$bankAccountName, $creditCardName] = $this->extractFinancialSourceNames($message, $normalized, $paymentMethod);

        // Clean extracted entities so artifacts like "Servi??o" become "Servico" and casing is consistent.
        $cleaner = app(IncomingMessageNormalizer::class);

        if ($description !== null) {
            $description = trim($cleaner->clean($description));
            $description = $description !== '' ? mb_convert_case($description, MB_CASE_TITLE, 'UTF-8') : null;
        }

        if ($categoryName !== null) {
            $categoryName = trim($cleaner->clean($categoryName));
            $categoryName = $categoryName !== '' ? mb_convert_case($categoryName, MB_CASE_TITLE, 'UTF-8') : null;
        }

        if ($bankAccountName !== null) {
            $bankAccountName = trim($cleaner->clean($bankAccountName));
            $bankAccountName = $bankAccountName !== '' ? mb_convert_case($bankAccountName, MB_CASE_TITLE, 'UTF-8') : null;
        }

        if ($creditCardName !== null) {
            $creditCardName = trim($cleaner->clean($creditCardName));
            $creditCardName = $creditCardName !== '' ? mb_convert_case($creditCardName, MB_CASE_TITLE, 'UTF-8') : null;
        }

        $payload = [
            'type' => $type,
            'amount' => $amount,
            'description' => $description,
            'date' => ($date ?? Carbon::now())->format('Y-m-d'),
            'category_name' => $categoryName,
            'bank_account_name' => $bankAccountName,
            'credit_card_name' => $creditCardName,
        ];

        if ($type === 'income' && empty($payload['category_name'])) {
            $payload['category_name'] = 'Salário';
        }

        if ($paymentMethod !== null) {
            $payload['payment_method'] = $paymentMethod;
        }

        return array_filter($payload, fn ($value) => $value !== null && $value !== '');
    }

    private function inferType(string $normalizedMessage): ?string
    {
        if ($this->containsAnyText($normalizedMessage, ['recebi', 'ganhei', 'ganho', 'entrou', 'tive um ganho'])) {
            return 'income';
        }

        if ($this->containsAnyText($normalizedMessage, ['gastei', 'paguei', 'comprei', 'tive um gasto'])) {
            return 'expense';
        }

        return null;
    }

    private function extractDescription(string $message, string $type): ?string
    {
        $patterns = $type === 'income'
            ? [
                '/(?:recebi|ganhei|entrou|tive\s+um\s+ganho)\s+(?:de\s+)?(?:r\$\s*)?\d+(?:[\.,]\d{1,2})?(?:\s*reais?)?\s+(?:de|do|da|em|com)\s+(.+)$/iu',
                '/(?:recebi|ganhei|entrou|tive\s+um\s+ganho)\s+(?:de\s+)?(?:r\$\s*)?\d+(?:[\.,]\d{1,2})?(?:\s*reais?)?\s+(.+)$/iu',
            ]
            : [
                '/(?:gastei|paguei|comprei|tive\s+um\s+gasto)\s+(?:de\s+)?(?:r\$\s*)?\d+(?:[\.,]\d{1,2})?(?:\s*reais?)?\s+(?:no|na|em|com)\s+(.+)$/iu',
                '/(?:gastei|paguei|comprei|tive\s+um\s+gasto)\s+(?:de\s+)?(?:r\$\s*)?\d+(?:[\.,]\d{1,2})?(?:\s*reais?)?\s+(.+)$/iu',
            ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches) === 1) {
                $description = trim($matches[1] ?? '');
                $description = $this->cleanupTrailingContext($description);

                return $description !== '' ? $description : null;
            }
        }

        return null;
    }

    private function extractCategoryName(string $message): ?string
    {
        if (preg_match('/(?:categoria|na categoria)\s+(.+?)(?:\s+(?:na conta|no cart[aã]o|no cartao|via|com|hoje|ontem|amanh[aã]|em\s+\d{1,2}\/\d{1,2}\/\d{2,4})|[,.]|$)/iu', $message, $matches) === 1) {
            $category = trim($matches[1] ?? '');
            $category = $this->cleanupTrailingContext($category);

            return $category !== '' ? mb_convert_case($category, MB_CASE_TITLE, 'UTF-8') : null;
        }

        return null;
    }

    private function extractFinancialSourceNames(string $message, string $normalizedMessage, ?string $paymentMethod): array
    {
        $bankAccountName = null;
        $creditCardName = null;

        if (preg_match('/(?:na conta|pela conta|via conta)\s+(.+?)(?:\s+(?:categoria|hoje|ontem|amanh[aã]|em\s+\d{1,2}\/\d{1,2}\/\d{2,4})|[,.]|$)/iu', $message, $matches) === 1) {
            $bankAccountName = $this->cleanupTrailingContext(trim($matches[1] ?? ''));
        }

        if (preg_match('/(?:no cart[aã]o|no cartao|pelo cart[aã]o|pelo cartao|via cart[aã]o|via cartao)\s+(.+?)(?:\s+(?:categoria|hoje|ontem|amanh[aã]|em\s+\d{1,2}\/\d{1,2}\/\d{2,4})|[,.]|$)/iu', $message, $matches) === 1) {
            $creditCardName = $this->cleanupTrailingContext(trim($matches[1] ?? ''));
        }

        if ($creditCardName === null && $paymentMethod === 'credit' && preg_match('/(?:no|via|com)\s+cr[eé]dito(?:\s+(.+?))?(?:[,.]|$)/iu', $message, $matches) === 1) {
            $candidate = $this->cleanupTrailingContext(trim($matches[1] ?? ''));
            $creditCardName = $candidate !== '' ? $candidate : null;
        }

        return [$bankAccountName ?: null, $creditCardName ?: null];
    }

    private function extractPaymentMethod(string $normalizedMessage): ?string
    {
        if ($this->containsAnyText($normalizedMessage, ['debito', 'débito'])) {
            return 'debit';
        }

        if ($this->containsAnyText($normalizedMessage, ['credito', 'crédito'])) {
            return 'credit';
        }

        if ($this->containsAnyText($normalizedMessage, ['pix'])) {
            return 'pix';
        }

        return null;
    }

    private function extractDate(string $originalMessage, string $normalizedMessage): ?Carbon
    {
        if ($this->containsAnyText($normalizedMessage, ['ontem'])) {
            return now()->subDay();
        }

        if ($this->containsAnyText($normalizedMessage, ['hoje'])) {
            return now();
        }

        if ($this->containsAnyText($normalizedMessage, ['amanha', 'amanhã'])) {
            return now()->addDay();
        }

        if (preg_match('/\b(\d{1,2})\/(\d{1,2})\/(\d{2,4})\b/u', $originalMessage, $matches) === 1) {
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

    private function cleanupTrailingContext(string $value): string
    {
        $value = preg_replace('/^[\p{Z}\p{P}]+|[\p{Z}\p{P}]+$/u', '', $value) ?? $value;
        $value = preg_replace('/^(?:um|uma)\s+/iu', '', $value) ?? $value;
        $value = preg_replace('/\b(?:no|na|via|com)\s+(?:d\S{0,2}bito|cr\S{0,2}dito|pix)\b/iu', '', $value) ?? $value;
        $value = preg_replace('/\b(?:hoje|ontem|amanha|amanhã)\b/iu', '', $value) ?? $value;
        $value = preg_replace('/\b\d{1,2}\/\d{1,2}\/\d{2,4}\b/u', '', $value) ?? $value;
        $value = preg_replace('/\bem\b$/iu', '', trim($value)) ?? $value;
        $value = preg_replace('/\s+(?:na conta|no cart[aã]o|no cartao|pela conta|pelo cart[aã]o|pelo cartao|via conta|via cart[aã]o|via cartao)\s+.+$/iu', '', $value) ?? $value;
        $value = preg_replace('/\s+(?:categoria|na categoria)\s+.+$/iu', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? $value;

        return trim($value);
    }
}
