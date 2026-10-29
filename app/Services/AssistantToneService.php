<?php

namespace App\Services;

use App\Models\User;

class AssistantToneService
{
    public const OPTIONS = [
        'friendly' => 'Amigável',
        'direct' => 'Direto',
        'professional' => 'Profissional',
        'rude' => 'Rude / irreverente',
    ];

    public function instruction(?User $user): string
    {
        return match ($user?->assistant_tone) {
            'direct' => 'Use um tom direto e conciso, sem perder a cordialidade.',
            'professional' => 'Use um tom profissional, claro e respeitoso.',
            'rude' => 'Use humor irreverente, provocador e brincalhão. Não insulte, humilhe, discrimine ou use palavrões contra o usuário. Em assuntos financeiros, de saúde, segurança e confirmações de ações, mantenha clareza, precisão e respeito.',
            default => 'Use um tom amigável, natural e prestativo.',
        };
    }
}
