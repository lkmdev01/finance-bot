<?php

namespace App\Services\WhatsApp;

class BudgetMessageParser
{
    public function parseEdit(string $message, ?string $fallbackCategoryName = null, array $fallbackPeriod = []): ?array
    {
        $normalized = $this->normalize($message);

        if (! $this->looksLikeEditIntent($normalized) && ! ($fallbackCategoryName && $this->containsEditVerb($normalized))) {
            return null;
        }

        $amount = $this->extractAmount($normalized);
        $categoryName = $this->extractCategoryName($message, $normalized) ?? $fallbackCategoryName;
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

        if (! $this->looksLikeDeleteIntent($normalized) && ! ($fallbackCategoryName && $this->containsDeleteVerb($normalized))) {
            return null;
        }

        $categoryName = $this->extractCategoryName($message, $normalized) ?? $fallbackCategoryName;
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
        if (! str_contains($message, 'orcamento')) {
            return false;
        }

        return $this->containsEditVerb($message);
    }

    public function looksLikeDeleteIntent(string $message): bool
    {
        if (! str_contains($message, 'orcamento')) {
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
        if (! preg_match_all('/(?:r\$\s*)?(\d+(?:[\.,]\d{1,2})?)(?:\s*mil)?/u', $message, $matches) || empty($matches[1])) {
            return null;
        }

        $raw = end($matches[1]);
        if ($raw === false) {
            return null;
        }

        $amount = (float) str_replace(',', '.', str_replace('.', '', $raw));

        if (preg_match('/'.preg_quote((string) $raw, '/').'\s*mil\b/u', $message) === 1) {
            $amount *= 1000;
        }

        return $amount > 0 ? $amount : null;
    }

    private function extractCategoryName(string $originalMessage, string $normalized): ?string
    {
        if (preg_match('/(?:orcamento|limite)\s+(?:de\s+)?(.+?)(?:\s+(?:para|pra|com|no|na|em)\s+(?:r\$\s*)?\d|\s+(?:mensal|anual|em)\b|[,.]|$)/iu', $originalMessage, $matches)) {
            $name = trim((string) ($matches[1] ?? ''));
            $name = preg_replace('/\b(para|pra|com|no|na|em)\b.*$/iu', '', $name) ?? $name;
            $name = trim($name, " \t\n\r\0\x0B-:");

            if ($name !== '' && ! in_array($this->normalize($name), ['orcamento', 'limite'], true)) {
                return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
            }
        }

        if (preg_match('/(?:para|de|do|da)\s+([\p{L}\p{N} _-]+)$/iu', $originalMessage, $matches)) {
            $name = trim((string) ($matches[1] ?? ''));
            $name = preg_replace('/\b(mensal|anual|hoje|amanha|amanhã)\b.*$/iu', '', $name) ?? $name;
            $name = trim($name, " \t\n\r\0\x0B-:");

            if ($name !== '') {
                return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
            }
        }

        return null;
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
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $converted !== false ? $converted : $value;
    }
}
