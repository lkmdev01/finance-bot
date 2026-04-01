<?php

namespace App\Support;

class BrazilTaxId
{
    public static function normalize(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $value);

        return $digits !== '' ? $digits : null;
    }

    public static function isValid(?string $value): bool
    {
        $digits = static::normalize($value);

        if (! $digits) {
            return false;
        }

        return match (strlen($digits)) {
            11 => static::isValidCpf($digits),
            14 => static::isValidCnpj($digits),
            default => false,
        };
    }

    public static function format(?string $value): ?string
    {
        $digits = static::normalize($value);

        if (! $digits) {
            return null;
        }

        if (strlen($digits) === 11) {
            return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $digits);
        }

        if (strlen($digits) === 14) {
            return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $digits);
        }

        return $digits;
    }

    protected static function isValidCpf(string $cpf): bool
    {
        if (preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $sum = 0;

            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $cpf[$i] * (($t + 1) - $i);
            }

            $digit = ((10 * $sum) % 11) % 10;

            if ((int) $cpf[$t] !== $digit) {
                return false;
            }
        }

        return true;
    }

    protected static function isValidCnpj(string $cnpj): bool
    {
        if (preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        $firstWeights = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $secondWeights = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        $firstDigit = static::calculateCnpjDigit(substr($cnpj, 0, 12), $firstWeights);
        $secondDigit = static::calculateCnpjDigit(substr($cnpj, 0, 12) . $firstDigit, $secondWeights);

        return $cnpj[12] == $firstDigit && $cnpj[13] == $secondDigit;
    }

    protected static function calculateCnpjDigit(string $base, array $weights): int
    {
        $sum = 0;

        foreach (str_split($base) as $index => $digit) {
            $sum += (int) $digit * $weights[$index];
        }

        $remainder = $sum % 11;

        return $remainder < 2 ? 0 : 11 - $remainder;
    }
}
