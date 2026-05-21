<?php

namespace App\Services\WhatsApp\Support;

use Illuminate\Support\Str;

trait NormalizesWhatsAppText
{
    protected function normalizeText(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return Str::ascii($value);
    }

    protected function containsAnyText(string $message, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($message, $this->normalizeText($needle))) {
                return true;
            }
        }

        return false;
    }
}
