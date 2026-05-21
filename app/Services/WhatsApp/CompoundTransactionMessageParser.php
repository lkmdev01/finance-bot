<?php

namespace App\Services\WhatsApp;

class CompoundTransactionMessageParser
{
    public function parse(string $message): array
    {
        $normalized = $this->normalize($message);
        $baseType = $this->resolveType($normalized);

        if ($baseType === null) {
            return [];
        }

        $segments = preg_split('/\s+(?:e|tambem|também|depois)\s+|\s*;\s*|\s*,\s*/u', trim($message)) ?: [];

        if (count($segments) < 2) {
            return [];
        }

        $payloads = [];

        foreach ($segments as $index => $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            $normalizedSegment = $this->normalize($segment);
            if ($index > 0 && $this->resolveType($normalizedSegment) === null) {
                $segment = ($baseType === 'income' ? 'recebi ' : 'gastei ') . $segment;
                $normalizedSegment = $this->normalize($segment);
            }

            $payload = $this->parseSingle($segment, $normalizedSegment, $baseType);
            if ($payload === null) {
                return [];
            }

            $payloads[] = $payload;
        }

        return count($payloads) >= 2 ? $payloads : [];
    }

    private function parseSingle(string $original, string $normalized, string $fallbackType): ?array
    {
        if (! preg_match('/(?:r\$\s*)?(\d+(?:[\.,]\d{1,2})?)/u', $normalized, $amountMatches)) {
            return null;
        }

        $amount = (float) str_replace(',', '.', str_replace('.', '', $amountMatches[1]));
        if ($amount <= 0) {
            return null;
        }

        $type = $this->resolveType($normalized) ?? $fallbackType;
        $description = preg_replace('/.*?(?:r\$\s*)?\d+(?:[\.,]\d{1,2})?\s*/u', '', $original, 1) ?? '';
        $description = preg_replace('/^(?:no|na|de|do|da|em|para)\s+/iu', '', trim($description)) ?? trim($description);
        $description = trim($description, " \t\n\r\0\x0B-:");

        return array_filter([
            'type' => $type,
            'amount' => $amount,
            'description' => $description !== '' ? $description : null,
            'category_name' => $description !== '' ? $description : null,
            'date' => now()->toDateString(),
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function resolveType(string $message): ?string
    {
        foreach (['recebi', 'ganhei', 'entrou'] as $keyword) {
            if (str_contains($message, $keyword)) {
                return 'income';
            }
        }

        foreach (['gastei', 'paguei', 'comprei'] as $keyword) {
            if (str_contains($message, $keyword)) {
                return 'expense';
            }
        }

        return null;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $converted !== false ? $converted : $value;
    }
}
