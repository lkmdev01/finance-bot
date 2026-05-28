<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppContact;

class CancelSubscriptionHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return $action === 'cancel_subscription';
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        $name = trim((string) ($result['subscription_data']['name'] ?? ''));

        if ($name === '') {
            $this->sendErrorMessage($job, 'Preciso do nome da assinatura para cancelar. Exemplo: cancelar assinatura Netflix.');

            return true;
        }

        $subscription = $user->subscriptions()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if (! $subscription instanceof Subscription) {
            $this->sendErrorMessage($job, 'Nao encontrei essa assinatura para cancelar. Se quiser, eu posso listar suas assinaturas atuais primeiro.');

            return true;
        }

        $before = $subscription->only(['is_active']);

        $subscription->is_active = false;
        $subscription->save();

        $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
            'reply_kind' => 'action',
            'undo' => [
                'kind' => 'subscription_cancel',
                'id' => $subscription->id,
                'before' => $before,
                'expires_at' => now()->addSeconds(60)->toIso8601String(),
            ],
            'entities' => [
                'topic' => 'subscriptions',
                'subscription_name' => $subscription->name,
            ],
        ]);

        $this->sendResponse($job, sprintf('Assinatura %s cancelada. Se quiser, eu posso listar as ativas ou revisar o impacto mensal delas.', $subscription->name), $user);

        return true;
    }
}
