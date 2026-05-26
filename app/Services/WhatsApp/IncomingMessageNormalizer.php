<?php

namespace App\Services\WhatsApp;

use App\Services\WhatsAppFormatter;
use Illuminate\Support\Str;

class IncomingMessageNormalizer
{
    public function clean(string $message): string
    {
        $message = WhatsAppFormatter::normalizeTextEncoding($message);
        $message = str_replace(["\r\n", "\r"], "\n", $message);
        $message = preg_replace('/[\x{200B}-\x{200F}\x{2060}\x{FEFF}]/u', '', $message) ?? $message;
        $message = preg_replace('/(?<=\pL)[\?�](?=\pL)/u', '', $message) ?? $message;
        $message = preg_replace('/^\s*chat[\s,:-]+/iu', '', $message) ?? $message;
        // Conservative typo fixes that improve intent detection.
        $message = preg_replace('/\bastei\s+(\d)/iu', 'gastei $1', $message) ?? $message;
        $message = preg_replace('/\s+/u', ' ', $message) ?? $message;

        return trim($message);
    }

    public function normalize(string $message): string
    {
        $message = mb_strtolower($this->clean($message));
        $message = Str::ascii($message);
        $message = preg_replace('/\s+/u', ' ', $message) ?? $message;

        return trim($message);
    }
}
