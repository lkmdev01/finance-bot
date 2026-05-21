<?php

namespace App\Services\WhatsApp;

use App\Models\User;

class CreditCardConversationService
{
    public function buildReply(User $user, string $message, array $state = []): array
    {
        $normalized = $this->normalize($message);
        $activeOnly = ! str_contains($normalized, 'cancelad') && ! str_contains($normalized, 'inativ');

        $cards = $user->creditCards()
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get();

        if ($cards->isEmpty()) {
            return [
                'reply' => $activeOnly
                    ? 'Voce ainda nao tem cartoes ativos cadastrados.'
                    : 'Voce nao tem cartoes inativos cadastrados.',
                'entities' => [
                    'topic' => 'credit_cards',
                    'credit_card_status' => $activeOnly ? 'active' : 'inactive',
                ],
            ];
        }

        $lines = $cards->map(fn ($card) => sprintf(
            '- %s: limite R$ %s | disponivel R$ %s',
            $card->name,
            number_format((float) $card->credit_limit, 2, ',', '.'),
            number_format((float) $card->available_limit, 2, ',', '.')
        ))->implode("\n");

        return [
            'reply' => ($activeOnly ? "Seus cartoes ativos:\n" : "Seus cartoes inativos:\n").$lines,
            'entities' => [
                'topic' => 'credit_cards',
                'credit_card_status' => $activeOnly ? 'active' : 'inactive',
                'credit_card_names' => $cards->pluck('name')->values()->all(),
            ],
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
