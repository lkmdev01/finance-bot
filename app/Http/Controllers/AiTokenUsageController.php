<?php

namespace App\Http\Controllers;

use App\Models\AiTokenUsageEvent;
use App\Models\AiUserTokenUsage;
use App\Services\AiUserTokenBudget;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiTokenUsageController extends Controller
{
    public function __invoke(Request $request, AiUserTokenBudget $budget): View
    {
        $user = $request->user();
        $timezone = (string) config('app.timezone', 'UTC');
        $now = CarbonImmutable::now($timezone);
        $monthStart = $now->startOfMonth();
        $period = $now->format('Y-m');
        $limit = max(0, (int) config('ai.user_monthly_token_limit', 20000));
        $hourlyThreshold = max(0, (int) config('ai.usage_alert_hourly_threshold', 5000));
        $hourStart = $now->startOfHour();

        $monthlyTotal = (int) AiUserTokenUsage::query()
            ->where('user_id', $user->getKey())
            ->where('period', $period)
            ->value('tokens_used');
        $agentUsage = AiTokenUsageEvent::query()
            ->where('user_id', $user->getKey())
            ->where('created_at', '>=', $monthStart)
            ->where('created_at', '<', $now->addSecond())
            ->selectRaw('agent_key, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens, SUM(total_tokens) as total_tokens')
            ->groupBy('agent_key')
            ->orderByDesc('total_tokens')
            ->get();
        $hourlyTotal = (int) AiTokenUsageEvent::query()
            ->where('user_id', $user->getKey())
            ->where('created_at', '>=', $hourStart)
            ->where('created_at', '<', $hourStart->addHour())
            ->sum('total_tokens');

        return view('pages.ai-usage.index', [
            'period' => $period,
            'monthlyTotal' => $monthlyTotal,
            'agentUsage' => $agentUsage,
            'hourlyTotal' => $hourlyTotal,
            'hourlyThreshold' => $hourlyThreshold,
            'monthlyLimit' => $limit,
            'isExempt' => $budget->isExempt($user),
            'retentionDays' => max(31, (int) config('ai.usage_event_retention_days', 90)),
            'recentSpikeAlerts' => $user->notifications()
                ->where('data->type', 'ai_token_usage_spike')
                ->latest()
                ->limit(5)
                ->get(),
        ]);
    }
}
