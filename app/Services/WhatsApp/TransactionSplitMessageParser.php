<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Str;

class TransactionSplitMessageParser
{
    public function parse(string $message): ?array
    {
        $normalized = $this->normalize($message);

        if (! $this->looksLikeSplitIntent($normalized)) {
            return null;
        }

        $reference = $this->extractReference($normalized);
        $items = $this->extractSplitItems($message);

        return array_filter([
            'reference' => $reference,
            'split_items' => $items,
        ], fn ($value) => $value !== null && $value !== []);
    }

    public function looksLikeSplitIntent(string $message): bool
    {
        return (str_contains($message, 'divide') || str_contains($message, 'dividir') || str_contains($message, 'separa') || str_contains($message, 'separar'))
            && str_contains($message, 'categoria');
    }

    private function extractReference(string $message): ?string
    {
        if (str_contains($message, 'penultimo')) {
            return 'penultimate';
        }

        if (str_contains($message, 'ultimo')) {
            return 'latest';
        }

        if (str_contains($message, 'esse') || str_contains($message, 'essa') || str_contains($message, 'aquele') || str_contains($message, 'aquela')) {
            return 'recent';
        }

        return null;
    }

    private function extractSplitItems(string $message): array
    {
        $matches = [];
        preg_match_all('/([\\p{L}][\\p{L}\\s_-]+?)\\s+(\\d+(?:[\\.,]\\d{1,2})?)/u', $message, $matches, PREG_SET_ORDER);

        return collect($matches)->map(function (array $match) {
            return [
                'category_name' => mb_convert_case(trim($match[1]), MB_CASE_TITLE, 'UTF-8'),
                'amount' => (float) str_replace(',', '.', str_replace('.', '', $match[2])),
            ];
        })->filter(fn (array $item) => $item['category_name'] !== '' && $item['amount'] > 0)->values()->all();
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return Str::ascii($value);
    }
}
