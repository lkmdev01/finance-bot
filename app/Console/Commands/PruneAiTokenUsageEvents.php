<?php

namespace App\Console\Commands;

use App\Models\AiTokenUsageEvent;
use Illuminate\Console\Command;

class PruneAiTokenUsageEvents extends Command
{
    protected $signature = 'ai:prune-token-usage-events';

    protected $description = 'Remove detalhamentos antigos de uso de IA e preserva os totais mensais';

    public function handle(): int
    {
        $days = min(3650, max(31, (int) config('ai.usage_event_retention_days', 90)));
        $cutoff = now()->subDays($days);
        $deleted = 0;

        do {
            $ids = AiTokenUsageEvent::query()
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(1000)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += AiTokenUsageEvent::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);

        $this->info("Detalhamentos removidos: {$deleted}. Totais mensais preservados.");

        return self::SUCCESS;
    }
}
