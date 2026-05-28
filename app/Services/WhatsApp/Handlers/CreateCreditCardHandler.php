<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\CreditCard;
use App\Models\User;
use App\Models\WhatsAppContact;
use Illuminate\Support\Facades\Validator;

class CreateCreditCardHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return $action === 'create_credit_card';
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        $data = [
            'name' => trim((string) ($result['credit_card_data']['name'] ?? '')),
            'credit_limit' => isset($result['credit_card_data']['credit_limit']) ? (float) $result['credit_card_data']['credit_limit'] : null,
            'is_active' => (bool) ($result['credit_card_data']['is_active'] ?? true),
        ];

        $validation = Validator::make($data, [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'credit_limit' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
        ]);

        if ($validation->fails()) {
            $this->sendErrorMessage($job, 'Nao consegui registrar esse cartao. Tente assim: registrar cartao de credito nubank limite de 5000.');
            return true;
        }

        $previous = CreditCard::query()
            ->where('user_id', $user->id)
            ->where('name', $data['name'])
            ->first();

        $card = CreditCard::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'name' => $data['name'],
            ],
            [
                'credit_limit' => $data['credit_limit'],
                'is_active' => $data['is_active'],
            ]
        );

        $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
            'reply_kind' => 'action',
            'undo' => $previous instanceof CreditCard
                ? [
                    'kind' => 'credit_card_update',
                    'id' => $card->id,
                    'before' => $previous->only(['credit_limit', 'is_active']),
                    'expires_at' => now()->addSeconds(60)->toIso8601String(),
                ]
                : [
                    'kind' => 'credit_card_create',
                    'id' => $card->id,
                    'expires_at' => now()->addSeconds(60)->toIso8601String(),
                ],
            'entities' => [
                'topic' => 'credit_cards',
                'credit_card_name' => $card->name,
            ],
        ]);

        $this->sendResponse($job, sprintf('Cartao %s registrado com limite de R$ %s.', $card->name, number_format((float) $card->credit_limit, 2, ',', '.')), $user);
        return true;
    }
}
