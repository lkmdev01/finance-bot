<?php

namespace App\Services\WhatsApp;

use App\Services\WhatsApp\Support\NormalizesWhatsAppText;
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

        $payload = [
            'type' => $type,
            'amount' => $amount,
            'description' => $description,
            'date' => ($date ?? Carbon::now())->format('Y-m-d'),
        ];

        if ($type === 'income') {
            $payload['category_name'] = 'Salário';
        }

        if ($paymentMethod !== null) {
            $payload['payment_method'] = $paymentMethod;
        }

        return $payload;
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
                '/(?:recebi|ganhei|entrou|tive\s+um\s+ganho)\s+(?:de\s+)?(?:r\$\s*)?\d+(?:[\.,]\d{1,2})?\s+(?:de|do|da|em|com)\s+(.+)$/iu',
                '/(?:recebi|ganhei|entrou|tive\s+um\s+ganho)\s+(?:de\s+)?(?:r\$\s*)?\d+(?:[\.,]\d{1,2})?\s+(.+)$/iu',
            ]
            : [
                '/(?:gastei|paguei|comprei|tive\s+um\s+gasto)\s+(?:de\s+)?(?:r\$\s*)?\d+(?:[\.,]\d{1,2})?\s+(?:no|na|em|com)\s+(.+)$/iu',
                '/(?:gastei|paguei|comprei|tive\s+um\s+gasto)\s+(?:de\s+)?(?:r\$\s*)?\d+(?:[\.,]\d{1,2})?\s+(.+)$/iu',
            ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches) === 1) {
                $description = trim($matches[1] ?? '');
                $description = preg_replace('/^[\p{Z}\p{P}]+|[\p{Z}\p{P}]+$/u', '', $description) ?? $description;
                $description = preg_replace('/^(?:um|uma)\s+/iu', '', $description) ?? $description;
                $description = preg_replace('/\b(?:no|na|via|com)\s+(?:d\S{0,2}bito|cr\S{0,2}dito|pix)\b/iu', '', $description) ?? $description;
                $description = preg_replace('/\b(?:hoje|ontem|amanha|amanhã)\b/iu', '', $description) ?? $description;
                $description = preg_replace('/\b\d{1,2}\/\d{1,2}\/\d{2,4}\b/u', '', $description) ?? $description;
                $description = preg_replace('/\s+/u', ' ', trim($description)) ?? $description;

                return $description !== '' ? $description : null;
            }
        }

        return null;
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
}
