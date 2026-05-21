<?php

namespace App\Services\WhatsApp\Support;

trait NormalizesWhatsAppText
{
    protected function normalizeText(string $value): string
    {
        return app(\App\Services\WhatsApp\IncomingMessageNormalizer::class)->normalize($value);
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
