<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\User;
use App\Models\WhatsAppContact;

class EditNoteHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return $action === 'edit_note';
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        $url = rtrim((string) config('app.url'), '/').'/notes';

        $this->sendResponse(
            $job,
            "Ainda nao edito notas pelo WhatsApp.\n\nPara editar, use o painel:\n{$url}",
            $user
        );

        $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
            'reply_kind' => 'message',
            'entities' => [
                'topic' => 'notes',
            ],
        ]);

        return true;
    }
}

