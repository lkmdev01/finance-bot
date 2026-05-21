<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\RecurringTransaction;
use App\Models\User;
use App\Models\WhatsAppContact;

class CancelRecurringTransactionHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return $action === 'cancel_recurring_transaction';
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        $description = trim((string) ($result['recurring_data']['description'] ?? ''));

        if ($description === '') {
            $this->sendErrorMessage($job, 'Preciso do nome da recorrencia para cancelar. Exemplo: cancelar recorrencia academia.');
            return true;
        }

        $recurring = $user->recurringTransactions()
            ->whereRaw('LOWER(description) = ?', [mb_strtolower($description)])
            ->first();

        if (! $recurring instanceof RecurringTransaction) {
            $this->sendErrorMessage($job, 'Nao encontrei essa recorrencia para cancelar. Se quiser, eu posso listar as recorrencias atuais primeiro.');
            return true;
        }

        $recurring->is_active = false;
        $recurring->save();

        $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
            'reply_kind' => 'action',
            'entities' => [
                'topic' => 'recurring_transactions',
                'recurring_transaction_id' => $recurring->id,
                'recurring_description' => $recurring->description,
            ],
        ]);

        $this->sendResponse($job, sprintf('Recorrencia %s cancelada. Se quiser, eu posso revisar as ativas ou criar outra para voce.', $recurring->description), $user);
        return true;
    }
}
