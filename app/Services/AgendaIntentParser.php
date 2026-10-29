<?php

namespace App\Services;

use App\Ai\AgendaAgent;
use App\Models\User;
use Carbon\CarbonInterface;

class AgendaIntentParser
{
    public function __construct(private readonly SpecializedAiFallbackService $fallback) {}

    public function parse(string $message, CarbonInterface $reference, string $timezone, User $user): array
    {
        $prompt = sprintf(
            "Data e hora de referência: %s\nFuso horário: %s\n\nPedido do usuário:\n%s",
            $reference->copy()->setTimezone($timezone)->toIso8601String(),
            $timezone,
            $message,
        );

        return $this->fallback->prompt($user, app(AgendaAgent::class)->forUser($user), $prompt, 'agenda');
    }
}
