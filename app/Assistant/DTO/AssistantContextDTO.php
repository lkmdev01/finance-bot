<?php

namespace App\Assistant\DTO;

use App\Models\User;
use App\Models\WhatsAppContact;

class AssistantContextDTO
{
    public function __construct(
        public readonly User $user,
        public readonly WhatsAppContact $contact,
        public readonly array $state,
        public readonly string $timezone,
        public readonly string $currentMonth,
        public readonly int $currentYear,
        public readonly ?string $lastAction,
        public readonly ?string $pendingAction,
    ) {}
}
