<?php

namespace App\Services\WhatsApp;

class TransactionActionMessageParser
{
    public function parseEdit(string $message, array $state = []): ?array
    {
        $normalized = $this->normalize($message);

        $payload = [
            'target_description' => $this->extractTargetDescription($message),
            'category_name' => $this->extractCategoryName($message),
            'reference' => $this->extractReference($normalized),
            'target_date_scope' => $this->extractDateScope($normalized),
            'amount' => $this->extractAmount($normalized),
            'payment_method' => $this->extractPaymentMethod($normalized),
            'bank_account_name' => $this->extractBankAccountName($message),
            'credit_card_name' => $this->extractCreditCardName($message),
        ];

        if (! $this->looksLikeEditIntent($normalized) && $payload['payment_method'] === null) {
            return null;
        }

        if ($payload['target_description'] === null && $payload['reference'] === null && ! empty($state['last_entities']['transaction_id'])) {
            $payload['reference'] = 'recent';
        }

        if (
            $payload['amount'] === null
            && $payload['payment_method'] === null
            && $payload['category_name'] === null
            && $payload['bank_account_name'] === null
            && $payload['credit_card_name'] === null
            && $payload['target_description'] === null
            && $payload['reference'] === null
            && $payload['target_date_scope'] === null
        ) {
            return null;
        }

        return array_filter($payload, fn ($value) => $value !== null && $value !== '');
    }

    public function parseDelete(string $message, array $state = []): ?array
    {
        $normalized = $this->normalize($message);

        if (! $this->looksLikeDeleteIntent($normalized)) {
            return null;
        }

        $payload = [
            'target_description' => $this->extractTargetDescription($message),
            'reference' => $this->extractReference($normalized),
            'target_date_scope' => $this->extractDateScope($normalized),
        ];

        if ($payload['target_description'] === null && $payload['reference'] === null) {
            $payload['reference'] = ! empty($state['last_entities']['transaction_id']) ? 'recent' : 'latest';
        }

        return array_filter($payload, fn ($value) => $value !== null && $value !== '');
    }

    public function looksLikeEditIntent(string $message): bool
    {
        foreach (['editar', 'edita', 'alterar', 'altera', 'ajustar', 'ajusta', 'mudar', 'muda', 'atualizar', 'atualiza', 'corrigir', 'corrige'] as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return false;
    }

    public function looksLikeDeleteIntent(string $message): bool
    {
        foreach (['apagar', 'apaga', 'deletar', 'deleta', 'excluir', 'exclui', 'remover', 'remove'] as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function extractAmount(string $message): ?float
    {
        if (! preg_match_all('/(?:r\$\s*)?(\d+(?:[\.,]\d{1,2})?)(?:\s*reais?)?/u', $message, $matches) || empty($matches[1])) {
            return null;
        }

        $raw = end($matches[1]);
        if ($raw === false) {
            return null;
        }

        $amount = (float) str_replace(',', '.', str_replace('.', '', $raw));

        return $amount > 0 ? $amount : null;
    }

    private function extractTargetDescription(string $message): ?string
    {
        if (preg_match('/(?:transacao|gasto|despesa|receita)\s+(?:com\s+)?(.+?)(?:\s+(?:para|de|do|da)\s+(?:r\$\s*)?\d.*|\s+(?:de\s+ontem|de\s+hoje)|[,.]|$)/iu', $message, $matches)) {
            $target = trim((string) ($matches[1] ?? ''));
            $target = trim($target, " \t\n\r\0\x0B-:");

            if ($target !== '' && ! in_array($this->normalize($target), ['transacao', 'gasto', 'despesa', 'receita'], true)) {
                return $target;
            }
        }

        return null;
    }

    private function extractCategoryName(string $message): ?string
    {
        if (preg_match('/\bde\s+([\p{L}\p{N} _-]+)$/iu', trim($message), $matches)) {
            $category = trim((string) ($matches[1] ?? ''));
            $category = trim($category, " \t\n\r\0\x0B-:");

            if ($category !== '' && ! in_array($this->normalize($category), ['ontem', 'hoje', 'debito', 'credito', 'pix'], true)) {
                return mb_convert_case($category, MB_CASE_TITLE, 'UTF-8');
            }
        }

        return null;
    }

    private function extractBankAccountName(string $message): ?string
    {
        if (preg_match('/(?:na conta|pela conta|via conta)\s+(.+?)(?:\s+(?:categoria|hoje|ontem)|[,.]|$)/iu', $message, $matches) === 1) {
            $name = trim((string) ($matches[1] ?? ''));
            $name = trim($name, " \t\n\r\0\x0B-:");
            return $name !== '' ? mb_convert_case($name, MB_CASE_TITLE, 'UTF-8') : null;
        }

        return null;
    }

    private function extractCreditCardName(string $message): ?string
    {
        if (preg_match('/(?:no cart(?:a|ã)o|pelo cart(?:a|ã)o|via cart(?:a|ã)o)\s+(.+?)(?:\s+(?:categoria|hoje|ontem)|[,.]|$)/iu', $message, $matches) === 1) {
            $name = trim((string) ($matches[1] ?? ''));
            $name = trim($name, " \t\n\r\0\x0B-:");
            return $name !== '' ? mb_convert_case($name, MB_CASE_TITLE, 'UTF-8') : null;
        }

        return null;
    }

    private function extractReference(string $message): ?string
    {
        if ($this->containsAny($message, ['penultimo', 'penultima'])) {
            return 'penultimate';
        }

        if ($this->containsAny($message, ['ultimo', 'ultima'])) {
            // Prefer conversation context (what the user last interacted with),
            // instead of "latest by date" (which breaks with future installments).
            return 'recent';
        }

        if ($this->containsAny($message, ['aquele', 'aquela', 'esse', 'essa', 'ele', 'ela', 'isso'])) {
            return 'recent';
        }

        return null;
    }

    private function extractPaymentMethod(string $message): ?string
    {
        if ($this->containsAny($message, ['debito'])) {
            return 'debit';
        }

        if ($this->containsAny($message, ['credito'])) {
            return 'credit';
        }

        if ($this->containsAny($message, ['pix'])) {
            return 'pix';
        }

        return null;
    }

    private function extractDateScope(string $message): ?string
    {
        if ($this->containsAny($message, ['ontem'])) {
            return 'yesterday';
        }

        if ($this->containsAny($message, ['hoje'])) {
            return 'today';
        }

        return null;
    }

    private function containsAny(string $message, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($message, $this->normalize($needle))) {
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
