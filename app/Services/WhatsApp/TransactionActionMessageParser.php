<?php

namespace App\Services\WhatsApp;

class TransactionActionMessageParser
{
    public function parseEdit(string $message, array $state = []): ?array
    {
        $normalized = $this->normalize($message);

        if (! $this->looksLikeEditIntent($normalized)) {
            return null;
        }

        $payload = [
            'target_description' => $this->extractTargetDescription($message),
            'reference' => $this->extractReference($normalized),
            'target_date_scope' => $this->extractDateScope($normalized),
            'amount' => $this->extractAmount($normalized),
        ];

        if ($payload['target_description'] === null && $payload['reference'] === null && ! empty($state['last_entities']['transaction_id'])) {
            $payload['reference'] = 'recent';
        }

        if ($payload['amount'] === null) {
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

    private function extractReference(string $message): ?string
    {
        if ($this->containsAny($message, ['ultimo', 'ultima'])) {
            return 'latest';
        }

        if ($this->containsAny($message, ['aquele', 'aquela', 'esse', 'essa', 'ele', 'ela', 'isso'])) {
            return 'recent';
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
