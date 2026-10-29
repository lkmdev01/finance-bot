<?php

namespace App\Services;

use App\Models\AiTokenUsageEvent;
use App\Models\User;
use App\Notifications\AiTokenUsageSpikeNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiTokenUsageSpikeAlertService
{
    public function check(User $user, AiTokenUsageEvent $latestEvent): void
    {
        $threshold = (int) config('ai.usage_alert_hourly_threshold', 5000);
        if ($threshold <= 0) {
            return;
        }

        $timezone = (string) config('app.timezone', 'UTC');
        $hourStart = CarbonImmutable::now($timezone)->startOfHour();
        $hourEnd = $hourStart->addHour();
        $tokensInHour = (int) AiTokenUsageEvent::query()
            ->where('user_id', $user->getKey())
            ->where('created_at', '>=', $hourStart)
            ->where('created_at', '<', $hourEnd)
            ->sum('total_tokens');

        if ($tokensInHour < $threshold) {
            return;
        }

        $deduplicationKey = 'ai-token-usage-spike:'.$user->getKey().':'.$hourStart->format('YmdH');
        if (! Cache::add($deduplicationKey, true, 7200)) {
            return;
        }

        try {
            $user->notify(new AiTokenUsageSpikeNotification($tokensInHour, $threshold, $hourStart->toIso8601String()));
        } catch (Throwable $exception) {
            Cache::forget($deduplicationKey);
            Log::warning('Não foi possível criar alerta de consumo de IA', [
                'user_id' => $user->getKey(),
                'usage_event_id' => $latestEvent->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }
}
