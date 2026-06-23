<?php

namespace App\Services;

class WhatsAppFormatter
{
    public static function format(string $message): string
    {
        $message = self::normalizeTextEncoding($message);

        return self::removeFormatting($message);
    }

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

        $direct = self::replaceKnownMojibake($text);
        if (self::mojibakeScore($direct) < self::mojibakeScore($text)) {
            return $direct;
        }

        $repaired = self::repairLatin1Mojibake($text);

        if (! is_string($repaired) || $repaired === '' || ! mb_check_encoding($repaired, 'UTF-8')) {
            return $text;
        }

        return self::mojibakeScore($repaired) < self::mojibakeScore($text) ? $repaired : $text;
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
        $formatted = 'R$ '.number_format($amount, 2, ',', '.');

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
        foreach (self::mojibakeMarkers() as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    private static function repairLatin1Mojibake(string $text): ?string
    {
        $candidate = @mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');

        if (! is_string($candidate) || $candidate === '') {
            return null;
        }

        return $candidate;
    }

    private static function mojibakeScore(string $text): int
    {
        $score = 0;

        foreach (self::mojibakeMarkers() as $marker) {
            $score += substr_count($text, $marker);
        }

        return $score;
    }

    private static function replaceKnownMojibake(string $text): string
    {
        return strtr($text, self::replacementMap());
    }

    /**
     * @return array<int, string>
     */
    private static function mojibakeMarkers(): array
    {
        return array_merge(array_keys(self::replacementMap()), ["\xC3\x83", "\xC3\x82", "\xEF\xBF\xBD"]);
    }

    /**
     * @return array<string, string>
     */
    private static function replacementMap(): array
    {
        return [
            "\xC3\x83\xC2\xA3" => 'a', // a tilde mojibake
            "\xC3\x83\xC2\xA1" => 'a',
            "\xC3\x83\xC2\xA2" => 'a',
            "\xC3\x83\xC2\xA9" => 'e',
            "\xC3\x83\xC2\xAA" => 'e',
            "\xC3\x83\xC2\xAD" => 'i',
            "\xC3\x83\xC2\xB3" => 'o',
            "\xC3\x83\xC2\xB4" => 'o',
            "\xC3\x83\xC2\xBA" => 'u',
            "\xC3\x83\xC2\xA7" => 'c',
            "\xC3\xA2\xC2\x80\xC2\xA2" => '-',
            "\xC3\xA2\xC2\x9D\xC2\x8C" => '',
            "\xC3\xB0\xC5\xB8" => '',
        ];
    }
}
