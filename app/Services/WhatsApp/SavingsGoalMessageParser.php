<?php

namespace App\Services\WhatsApp;

class SavingsGoalMessageParser
{
    public function parsePartialCreate(string $message): ?array
    {
        $normalized = $this->normalize($message);

        if (! $this->looksLikeCreateIntent($normalized)) {
            return null;
        }

        $amount = $this->extractAmount($normalized);
        $name = $this->extractGoalName($message, $normalized);
        $targetDate = $this->extractTargetDate($normalized);

        if ($amount === null && $name === null && $targetDate === null) {
            return null;
        }

        return array_filter([
            'name' => $name,
            'target_amount' => $amount,
            'target_date' => $targetDate,
            'description' => null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function parse(string $message): ?array
    {
        $partial = $this->parsePartialCreate($message);

        if ($partial === null || empty($partial['name']) || ! isset($partial['target_amount'])) {
            return null;
        }

        return $partial;
    }

    public function parseCreateFollowUp(string $message, array $pendingGoal = []): ?array
    {
        $normalized = $this->normalize($message);
        $amount = $this->extractAmount($normalized) ?? ($pendingGoal['target_amount'] ?? null);
        $name = $this->extractGoalName($message, $normalized) ?? ($pendingGoal['name'] ?? null);
        $targetDate = $this->extractTargetDate($normalized) ?? ($pendingGoal['target_date'] ?? null);

        return array_filter([
            'name' => $name,
            'target_amount' => $amount,
            'target_date' => $targetDate,
            'description' => $pendingGoal['description'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function parseEdit(string $message, ?string $fallbackName = null): ?array
    {
        $normalized = $this->normalize($message);

        if (! $this->looksLikeEditIntent($normalized) && ! ($fallbackName && $this->containsEditVerb($normalized))) {
            return null;
        }

        $amount = $this->extractAmount($normalized);
        $name = $this->extractGoalName($message, $normalized) ?? $fallbackName;
        $targetDate = $this->extractTargetDate($normalized);

        if ($name === null || ($amount === null && $targetDate === null)) {
            return null;
        }

        return array_filter([
            'name' => $name,
            'target_amount' => $amount,
            'target_date' => $targetDate,
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function looksLikeCreateIntent(string $message): bool
    {
        $hasGoalCue = str_contains($message, 'meta')
            || str_contains($message, 'objetivo')
            || str_contains($message, 'poupanca')
            || str_contains($message, 'guardar')
            || str_contains($message, 'juntar')
            || str_contains($message, 'economizar');

        if (! $hasGoalCue) {
            return false;
        }

        foreach (['criar', 'crie', 'nova', 'novo', 'definir', 'defina', 'cadastrar', 'cadastre', 'guardar', 'juntar', 'economizar'] as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return preg_match('/\bmeta\s+[\p{L}\p{N}]/u', $message) === 1;
    }

    public function looksLikeEditIntent(string $message): bool
    {
        $hasGoalCue = str_contains($message, 'meta')
            || str_contains($message, 'objetivo')
            || str_contains($message, 'poupanca');

        if (! $hasGoalCue) {
            return false;
        }

        foreach (['editar', 'edita', 'alterar', 'altera', 'ajustar', 'ajusta', 'mudar', 'muda', 'atualizar', 'atualiza'] as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return preg_match('/\bmeta\s+[\p{L}\p{N}].*\b(?:para|com)\s+(?:r\$\s*)?\d/u', $message) === 1;
    }

    private function containsEditVerb(string $message): bool
    {
        foreach (['editar', 'edita', 'alterar', 'altera', 'ajustar', 'ajusta', 'mudar', 'muda', 'atualizar', 'atualiza'] as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function extractAmount(string $message): ?float
    {
        if (preg_match('/(\d+(?:[\.,]\d{1,2})?)\s*mil\b/u', $message, $matches)) {
            return (float) str_replace(',', '.', $matches[1]) * 1000;
        }

        if (preg_match('/(?:r\$\s*)?(\d+(?:[\.,]\d{1,2})?)/u', $message, $matches)) {
            $raw = str_replace('.', '', $matches[1]);
            $amount = (float) str_replace(',', '.', $raw);

            return $amount > 0 ? $amount : null;
        }

        return null;
    }

    private function extractGoalName(string $originalMessage, string $normalized): ?string
    {
        $name = null;

        if (preg_match('/(?:meta|objetivo)\s+(?:nova\s+|novo\s+)?(.+?)(?:\s+(?:com\s+valor(?:\s+de)?|de|para)\s+(?:r\$\s*)?\d.*)?$/iu', $originalMessage, $matches)) {
            $name = trim($matches[1] ?? '');
        }

        if (($name === null || $name === '') && preg_match('/(?:guardar|juntar|economizar)\s+(?:r\$\s*)?\d+(?:[\.,]\d{1,2})?(?:\s*mil)?\s+para\s+(.+)$/iu', $originalMessage, $matches)) {
            $name = trim($matches[1] ?? '');
        }

        if ($name === null || $name === '') {
            return null;
        }

        $name = preg_replace('/\b(com\s+valor|valor|de|para|com|ate|ate).*$/iu', '', $name) ?? $name;
        $name = preg_replace('/\s+\d+(?:[\.,]\d{1,2})?\s*(?:mil)?$/iu', '', $name) ?? $name;
        $name = trim($name, " \t\n\r\0\x0B-:");

        if ($name === '' || in_array($this->normalize($name), ['meta', 'objetivo', 'nova meta'], true)) {
            return null;
        }

        return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
    }

    private function extractTargetDate(string $message): ?string
    {
        if (preg_match('/\b(20\d{2})\b/u', $message, $yearMatches)) {
            $year = (int) $yearMatches[1];

            if (preg_match('/\b(janeiro|fevereiro|marco|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)\b/u', $message, $monthMatches)) {
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

                $month = $months[$monthMatches[1]] ?? null;

                if ($month !== null) {
                    return now()->setDate($year, $month, 1)->endOfMonth()->toDateString();
                }
            }

            return now()->setDate($year, 12, 31)->toDateString();
        }

        return null;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $converted !== false ? $converted : $value;
    }
}
