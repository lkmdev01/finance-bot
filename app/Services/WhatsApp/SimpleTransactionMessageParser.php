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

        $payload = [
            'type' => $type,
            'amount' => $amount,
            'description' => $description,
            'date' => Carbon::now()->format('Y-m-d'),
        ];

        if ($type === 'income') {
            $payload['category_name'] = 'Salário';
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

                return $description !== '' ? $description : null;
            }
        }

        return null;
    }
}
