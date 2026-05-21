<?php

namespace App\Services\WhatsApp;

class CreditCardMessageParser
{
    public function looksLikeCreateIntent(string $message): bool
    {
        return $this->hasCardCue($message)
            && $this->containsAny($message, ['criar', 'crie', 'registrar', 'registre', 'cadastrar', 'cadastre', 'novo', 'nova']);
    }

    public function looksLikeQueryIntent(string $message): bool
    {
        return $this->hasCardCue($message)
            && $this->containsAny($message, ['quais', 'qual', 'tenho', 'listar', 'liste', 'ativos', 'ativas']);
    }

    public function parseCreate(string $message): ?array
    {
        $normalized = $this->normalize($message);

        if (! $this->looksLikeCreateIntent($normalized)) {
            return null;
        }

        $name = $this->extractName($normalized);
        $limit = $this->extractLimit($normalized);

        if ($name === null || $limit === null) {
            return null;
        }

        return [
            'name' => $name,
            'credit_limit' => $limit,
            'is_active' => true,
        ];
    }

    private function extractName(string $message): ?string
    {
        if (preg_match('/(?:cartao(?:\s+de\s+credito)?|credito)\s+(.+?)(?:\s+(?:limite|com|de|r\$|\d)|[,.]|$)/i', $message, $matches) !== 1) {
            return null;
        }

        $name = trim((string) ($matches[1] ?? ''));
        $name = trim($name, " \t\n\r\0\x0B-:");

        return $name !== '' ? mb_convert_case($name, MB_CASE_TITLE, 'UTF-8') : null;
    }

    private function extractLimit(string $message): ?float
    {
        if (preg_match('/limite\s+(?:de\s+)?(?:r\$\s*)?(\d+(?:[\.,]\d{1,2})?)/i', $message, $matches) !== 1) {
            return null;
        }

        $raw = str_replace('.', '', $matches[1]);
        $value = (float) str_replace(',', '.', $raw);

        return $value > 0 ? $value : null;
    }

    private function hasCardCue(string $message): bool
    {
        return $this->containsAny($message, ['cartao', 'cartoes', 'cartao de credito', 'cartoes de credito', 'credito']);
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
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $converted !== false ? $converted : $value;
    }
}