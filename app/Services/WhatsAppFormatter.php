<?php

namespace App\Services;

class WhatsAppFormatter
{
    /**
     * Formats a message for WhatsApp and attempts to repair mojibake.
     */
    public static function format(string $message): string
    {
        $message = self::normalizeTextEncoding($message);

        return self::removeFormatting($message);
    }

    /**
     * Tries to repair strings that were mis-decoded (UTF-8 bytes interpreted as Windows-1252).
     * This shows up as sequences like "Ã£", "Ã©", "â€“", etc.
     */
    public static function normalizeTextEncoding(?string $text): string
    {
        $text = (string) ($text ?? '');

        if ($text === '') {
            return '';
        }

        $text = preg_replace("/\r\n?/", "\n", $text) ?? $text;

        if (! self::containsPotentialMojibake($text)) {
            return $text;
        }

        $repaired = @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        if (! is_string($repaired) || $repaired === '') {
            return $text;
        }

        return self::mojibakeScore($repaired) < self::mojibakeScore($text)
            ? $repaired
            : $text;
    }

    public static function bold(string $text): string
    {
        return "*{$text}*";
    }

    public static function italic(string $text): string
    {
        return "_{$text}_";
    }

    public static function strikethrough(string $text): string
    {
        return "~{$text}~";
    }

    public static function monospace(string $text): string
    {
        return "```{$text}```";
    }

    public static function formatMoney(float $amount, bool $bold = true): string
    {
        $formatted = 'R$ ' . number_format($amount, 2, ',', '.');

        return $bold ? self::bold($formatted) : $formatted;
    }

    public static function formatTitle(string $title): string
    {
        return self::bold($title);
    }

    public static function formatList(array $items, string $prefix = '-'): string
    {
        return implode("\n", array_map(fn ($item) => "{$prefix} {$item}", $items));
    }

    public static function formatBalance(float $balance): string
    {
        $status = $balance >= 0 ? 'Saldo disponivel' : 'Saldo negativo';

        return self::formatTitle($status).":\n"
            .self::formatMoney($balance)."\n\n"
            .($balance < 0 ? 'Atencao: seu saldo esta negativo.' : 'Tudo certo.');
    }

    public static function formatTransactionCreated(array $data): string
    {
        $type = ($data['type'] ?? 'expense') === 'income' ? 'receita' : 'despesa';

        $message = self::formatTitle("Registrei sua {$type}!")."\n\n";
        $message .= self::bold('Valor:').' '.self::formatMoney((float) ($data['amount'] ?? 0), false)."\n";

        if (! empty($data['description'])) {
            $message .= self::bold('Descricao:').' '.self::normalizeTextEncoding((string) $data['description'])."\n";
        }

        if (! empty($data['category'])) {
            $message .= self::bold('Categoria:').' '.self::normalizeTextEncoding((string) $data['category'])."\n";
        }

        if (! empty($data['date'])) {
            $message .= self::bold('Data:')." {$data['date']}\n";
        }

        return $message;
    }

    private static function removeFormatting(string $text): string
    {
        $text = preg_replace('/\*{2,}/', '*', $text) ?? $text;
        $text = preg_replace('/_{2,}/', '_', $text) ?? $text;
        $text = preg_replace('/~{2,}/', '~', $text) ?? $text;

        return $text;
    }

    private static function containsPotentialMojibake(string $text): bool
    {
        foreach (['Ã', 'Â', 'â', 'ðŸ'] as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    private static function mojibakeScore(string $text): int
    {
        $score = 0;

        foreach (['Ã', 'Â', 'â', 'ðŸ'] as $marker) {
            $score += substr_count($text, $marker);
        }

        return $score;
    }
}
