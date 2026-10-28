<?php

namespace App\Services;

use App\Ai\TaskAgent;
use Carbon\CarbonInterface;

class TaskIntentParser
{
    public function parse(string $message, CarbonInterface $reference, string $timezone): array
    {
        $prompt = sprintf(
            "Data e hora de referência: %s\nFuso horário: %s\n\nPedido do usuário:\n%s",
            $reference->copy()->setTimezone($timezone)->toIso8601String(),
            $timezone,
            $message,
        );

        return app(TaskAgent::class)->prompt($prompt)->toArray();
    }
}
