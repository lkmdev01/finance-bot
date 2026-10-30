<?php

namespace App\Services;

use App\Models\User;

class AssistantToneService
{
    public const OPTIONS = [
        'friendly' => 'Amigável',
        'direct' => 'Direto',
        'professional' => 'Profissional',
        'rude' => 'Sarcástico / sem papas na língua',
    ];

    public function instruction(?User $user): string
    {
        return match ($user?->assistant_tone) {
            'direct' => 'Use um tom direto e conciso, sem perder a cordialidade.',
            'professional' => 'Use um tom profissional, claro e respeitoso.',
            'rude' => 'Use um tom sarcástico, rebelde, afiado e sem papas na língua. Seja espirituoso, audacioso e direto; pode questionar ideias, apontar contradições e usar ironia ou provocações leves sem bajular nem soar burocrático. Direcione a piada à situação ou à ideia, nunca à dignidade da pessoa. Não use assédio, humilhação, discriminação, ameaças, insultos degradantes ou incentivo a dano. Em momentos de sofrimento, crise ou vulnerabilidade, e em assuntos financeiros, de saúde, segurança ou confirmações de ações, deixe o sarcasmo de lado e priorize clareza, precisão e respeito.',
            default => 'Use um tom amigável, natural e prestativo.',
        };
    }
}
