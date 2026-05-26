<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Str;

class BudgetMessageParser
{
    public function parseCreate(string $message): ?array
    {
        $normalized = $this->normalize($message);

        if (! $this->looksLikeCreateIntent($normalized)) {
            return null;
        }

        $amount = $this->extractAmount($message) ?? $this->extractAmount($normalized);
        $categoryName = $this->extractCategoryNameForCreate($message);
        $period = $this->extractPeriod($normalized, []);

        if ($amount === null || $categoryName === null) {
            return null;
        }

        return array_filter([
            'category_name' => $categoryName,
            'amount' => $amount,
            'period' => $period['period'] ?? 'monthly',
            'year' => $period['year'] ?? now()->year,
            'month' => ($period['period'] ?? 'monthly') === 'monthly' ? ($period['month'] ?? now()->month) : null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function looksLikeCreateIntent(string $message): bool
    {
        if (! str_contains($message, 'orcamento') && ! str_contains($message, 'oramento') && preg_match('/or.{0,4}amento/i', $message) !== 1) {
            return false;
        }

        return $this->containsCreateVerb($message);
    }

    public function parseEdit(string $message, ?string $fallbackCategoryName = null, array $fallbackPeriod = []): ?array
    {
        $normalized = $this->normalize($message);

        if (! $this->looksLikeEditIntent($normalized) && ! $this->containsEditVerb($normalized)) {
            return null;
        }

        $amount = $this->extractAmount($message) ?? $this->extractAmount($normalized);
        $categoryName = $this->extractCategoryName($message) ?? $fallbackCategoryName;
        $period = $this->extractPeriod($normalized, $fallbackPeriod);

        if ($categoryName === null || ($amount === null && $period === [])) {
            return null;
        }

        return array_filter([
            'category_name' => $categoryName,
            'amount' => $amount,
            'period' => $period['period'] ?? null,
            'year' => $period['year'] ?? null,
            'month' => $period['month'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function parseDelete(string $message, ?string $fallbackCategoryName = null, array $fallbackPeriod = []): ?array
    {
        $normalized = $this->normalize($message);

        if (! $this->looksLikeDeleteIntent($normalized) && ! $this->containsDeleteVerb($normalized)) {
            return null;
        }

        $categoryName = $this->extractCategoryName($message) ?? $fallbackCategoryName;
        $period = $this->extractPeriod($normalized, $fallbackPeriod);

        if ($categoryName === null) {
            return null;
        }

        return array_filter([
            'category_name' => $categoryName,
            'period' => $period['period'] ?? null,
            'year' => $period['year'] ?? null,
            'month' => $period['month'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function looksLikeEditIntent(string $message): bool
    {
        if (! str_contains($message, 'orcamento') && ! str_contains($message, 'oramento') && preg_match('/or.{0,4}amento/i', $message) !== 1) {
            return false;
        }

        return $this->containsEditVerb($message);
    }

    public function looksLikeDeleteIntent(string $message): bool
    {
        if (! str_contains($message, 'orcamento') && ! str_contains($message, 'oramento') && preg_match('/or.{0,4}amento/i', $message) !== 1) {
            return false;
        }

        return $this->containsDeleteVerb($message);
    }

    private function containsEditVerb(string $message): bool
    {
        foreach (['editar', 'edita', 'alterar', 'altera', 'ajustar', 'ajusta', 'mudar', 'muda', 'atualizar', 'atualiza', 'corrigir', 'corrige'] as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function containsCreateVerb(string $message): bool
    {
        foreach (['criar', 'cria', 'definir', 'define', 'registrar', 'registra', 'setar', 'seta'] as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function containsDeleteVerb(string $message): bool
    {
        foreach (['cancelar', 'cancela', 'apagar', 'apaga', 'excluir', 'exclui', 'deletar', 'deleta', 'remover', 'remove'] as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function extractAmount(string $message): ?float
    {
        if (! preg_match_all('/(?:r\$\s*)?(\d+(?:[\.,]\d{1,2})?)(?:\s*mil)?/i', $message, $matches) || empty($matches[1])) {
            return null;
        }

        $raw = end($matches[1]);
        if ($raw === false) {
            return null;
        }

        $amount = (float) str_replace(',', '.', str_replace('.', '', $raw));

        if (preg_match('/'.preg_quote((string) $raw, '/').'\s*mil\b/i', $message) === 1) {
            $amount *= 1000;
        }

        return $amount > 0 ? $amount : null;
    }

    private function extractCategoryName(string $message): ?string
    {
        $normalized = $this->normalize($message);
        $asciiMessage = Str::ascii($message);

        if (preg_match('/or.*?amento\s+de\s+([\p{L}\p{N} _?-]+?)(?:\s+(?:para|pra|com|no|na|em)\b|[,.]|$)/iu', $normalized, $matches)) {
            return $this->normalizeCategoryName((string) ($matches[1] ?? ''));
        }

        if (preg_match('/or.*?amento\s+([\p{L}\p{N} _?-]+?)(?:\s+(?:para|pra|com|no|na|em)\b|[,.]|$)/iu', $normalized, $matches)) {
            return $this->normalizeCategoryName((string) ($matches[1] ?? ''));
        }

        if (preg_match('/(?:para|pra)\s+(?:r\$\s*)?\d+(?:[\.,]\d{1,2})?\s+o\s+or.*?amento\s+de\s+([\p{L}\p{N} _?-]+)$/iu', $normalized, $matches)) {
            return $this->normalizeCategoryName((string) ($matches[1] ?? ''));
        }

        if (preg_match('/o\s+or.*?amento\s+de\s+([\p{L}\p{N} _?-]+)$/iu', $normalized, $matches)) {
            return $this->normalizeCategoryName((string) ($matches[1] ?? ''));
        }

        if (preg_match('/\ba\s+de\s+([a-z0-9 _-]+)$/i', Str::ascii(mb_strtolower($message)), $matches)) {
            return $this->normalizeCategoryName((string) ($matches[1] ?? ''));
        }

        if (preg_match('/\bde\s+([a-z0-9 _-]+)$/i', Str::ascii(mb_strtolower($message)), $matches)) {
            return $this->normalizeCategoryName((string) ($matches[1] ?? ''));
        }

        $parts = preg_split('/\s+de\s+/iu', $normalized);
        if (is_array($parts) && count($parts) >= 2) {
            $tail = trim((string) end($parts));
            if ($tail !== '') {
                return $this->normalizeCategoryName($tail);
            }
        }

        return null;
    }

    private function extractCategoryNameForCreate(string $message): ?string
    {
        $normalized = $this->normalize($message);

        // Pattern: "criar orcamento de 500 para compras"
        if (preg_match('/or.*?amento\\s+de\\s+(?:r\\$\\s*)?\\d+(?:[\\.,]\\d{1,2})?(?:\\s*mil)?\\s+(?:para|pra)\\s+([\\p{L}\\p{N} _?-]+?)(?:\\s+em\\b|[,.]|$)/iu', $normalized, $matches) === 1) {
            return $this->normalizeCategoryName((string) ($matches[1] ?? ''));
        }

        // Pattern: "criar orcamento de alimentacao de 700" / "orcamento de alimentacao 700"
        if (preg_match('/or.*?amento\\s+de\\s+([\\p{L}\\p{N} _?-]+?)\\s+(?:de\\s+)?(?:r\\$\\s*)?\\d+(?:[\\.,]\\d{1,2})?(?:\\s*mil)?(?:\\b|$)/iu', $normalized, $matches) === 1) {
            return $this->normalizeCategoryName((string) ($matches[1] ?? ''));
        }

        // Fallback to existing extractor (handles "a de X", "de X", etc.)
        return $this->extractCategoryName($message);
    }

    private function normalizeCategoryName(string $name): ?string
    {
        $name = trim($name, " \t\n\r\0\x0B-:");
        $name = preg_replace('/\b(mensal|anual|hoje|amanha)\b.*$/iu', '', $name) ?? $name;
        $name = trim($name, " \t\n\r\0\x0B-:");
        $name = Str::ascii($name);

        if ($name === '' || in_array($this->normalize($name), ['orcamento', 'limite'], true)) {
            return null;
        }

        return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
    }

    private function extractPeriod(string $message, array $fallbackPeriod): array
    {
        $year = (int) ($fallbackPeriod['year'] ?? now()->year);
        $month = array_key_exists('month', $fallbackPeriod) ? $fallbackPeriod['month'] : now()->month;
        $period = (string) ($fallbackPeriod['period'] ?? ((int) ($month ?? 0) > 0 ? 'monthly' : 'yearly'));

        if (preg_match('/\b(anual|ano inteiro|ano)\b/u', $message)) {
            $period = 'yearly';
            $month = null;
        }

        if (preg_match('/\b(20\d{2})\b/u', $message, $yearMatches)) {
            $year = (int) $yearMatches[1];
        }

        $months = [
            'janeiro' => 1,
            'fevereiro' => 2,
            'marco' => 3,
            'abril' => 4,
            'maio' => 5,
            'junho' => 6,
            'julho' => 7,
            'agosto' => 8,
            'setembro' => 9,
            'outubro' => 10,
            'novembro' => 11,
            'dezembro' => 12,
        ];

        foreach ($months as $label => $number) {
            if (str_contains($message, $label)) {
                $period = 'monthly';
                $month = $number;
                break;
            }
        }

        return [
            'period' => $period,
            'year' => $year,
            'month' => $period === 'yearly' ? null : $month,
        ];
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return Str::ascii($value);
    }
}
