<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\User;
use App\Models\WhatsAppContact;

interface ActionHandlerInterface
{
    /**
     * Determines if this handler should process the given action.
     */
    public function canHandle(?string $action): bool;

    /**
     * Handles the action.
     * Returns true if processing should stop, false if it should continue.
     */
    public function handle(
        ?string $action,
        array &$result,
        User $user,
        WhatsAppContact $contact,
        ProcessWhatsAppMessage $job
    ): bool;
}
