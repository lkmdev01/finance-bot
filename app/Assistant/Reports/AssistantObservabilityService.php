<?php

namespace App\Assistant\Reports;

use App\Models\WhatsAppConversationLog;
use Illuminate\Support\Collection;

class AssistantObservabilityService
{
    public function summary(int $days = 14, int $sampleSize = 1000): array
    {
        $logs = WhatsAppConversationLog::query()
            ->latest('id')
            ->where('created_at', '>=', now()->subDays($days))
            ->limit($sampleSize)
            ->get();

        return [
            'period_days' => $days,
            'sample_size' => $logs->count(),
            'totals' => $this->buildTotals($logs),
            'by_intent' => $this->buildIntentBreakdown($logs),
            'recent_failures' => $this->buildRecentFailures($logs),
            'top_unknown_messages' => $this->buildTopUnknownMessages($logs),
            'regression_backlog' => $this->buildRegressionBacklog($logs),
        ];
    }

    private function buildTotals(Collection $logs): array
    {
        $total = $logs->count();
        $errors = $logs->where('status', 'error')->count();
        $unknowns = $logs->filter(fn (WhatsAppConversationLog $log) => $this->assistantIntent($log) === 'unknown')->count();
        $usedAi = $logs->where('used_ai', true)->count();

        return [
            'total' => $total,
            'errors' => $errors,
            'unknowns' => $unknowns,
            'used_ai' => $usedAi,
            'success_rate' => $total > 0 ? round((($total - $errors) / $total) * 100, 1) : 0.0,
        ];
    }

    private function buildIntentBreakdown(Collection $logs): array
    {
        return $logs
            ->groupBy(fn (WhatsAppConversationLog $log) => $this->assistantIntent($log))
            ->map(function (Collection $intentLogs, string $intent) {
                $missingFields = $intentLogs
                    ->flatMap(function (WhatsAppConversationLog $log) {
                        $fields = data_get($log->metadata, 'assistant_missing_fields', []);

                        return is_array($fields) ? $fields : [];
                    })
                    ->countBy()
                    ->sortDesc()
                    ->take(3)
                    ->all();

                $avgConfidence = round(
                    $intentLogs
                        ->map(fn (WhatsAppConversationLog $log) => (float) data_get($log->metadata, 'assistant_confidence', 0))
                        ->filter(fn (float $value) => $value > 0)
                        ->avg() ?? 0,
                    2
                );

                $total = $intentLogs->count();
                $errors = $intentLogs->where('status', 'error')->count();
                $usedAi = $intentLogs->where('used_ai', true)->count();

                return [
                    'intent' => $intent,
                    'total' => $total,
                    'errors' => $errors,
                    'used_ai' => $usedAi,
                    'success_rate' => $total > 0 ? round((($total - $errors) / $total) * 100, 1) : 0.0,
                    'avg_confidence' => $avgConfidence,
                    'top_missing_fields' => $missingFields,
                    'last_seen_at' => optional($intentLogs->first()?->created_at)?->toDateTimeString(),
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    private function buildRecentFailures(Collection $logs): array
    {
        return $logs
            ->filter(function (WhatsAppConversationLog $log) {
                return $log->status === 'error'
                    || $this->assistantIntent($log) === 'unknown';
            })
            ->take(20)
            ->map(fn (WhatsAppConversationLog $log) => [
                'message' => $log->message,
                'assistant_intent' => $this->assistantIntent($log),
                'status' => $log->status,
                'action' => $log->action,
                'error_message' => $log->error_message,
                'used_ai' => $log->used_ai,
                'created_at' => optional($log->created_at)?->toDateTimeString(),
            ])
            ->values()
            ->all();
    }

    private function buildTopUnknownMessages(Collection $logs): array
    {
        return $logs
            ->filter(fn (WhatsAppConversationLog $log) => $this->assistantIntent($log) === 'unknown')
            ->groupBy('message')
            ->map(fn (Collection $group, string $message) => [
                'message' => $message,
                'count' => $group->count(),
                'last_seen_at' => optional($group->first()?->created_at)?->toDateTimeString(),
            ])
            ->sortByDesc('count')
            ->take(10)
            ->values()
            ->all();
    }

    private function buildRegressionBacklog(Collection $logs): array
    {
        $items = collect();

        $unknowns = $logs
            ->filter(fn (WhatsAppConversationLog $log) => $this->assistantIntent($log) === 'unknown')
            ->groupBy('message')
            ->map(function (Collection $group, string $message) {
                return [
                    'priority' => 'high',
                    'reason' => 'Mensagem recorrente ainda cai como unknown',
                    'message' => $message,
                    'intent' => 'unknown',
                    'count' => $group->count(),
                    'suggested_example' => [
                        'message' => $message,
                        'expected_intent' => 'unknown',
                    ],
                ];
            })
            ->sortByDesc('count')
            ->take(5)
            ->values();

        $items = $items->merge($unknowns);

        $missingFieldHotspots = $logs
            ->groupBy(fn (WhatsAppConversationLog $log) => $this->assistantIntent($log))
            ->flatMap(function (Collection $intentLogs, string $intent) {
                return $intentLogs
                    ->flatMap(function (WhatsAppConversationLog $log) use ($intent) {
                        $fields = data_get($log->metadata, 'assistant_missing_fields', []);
                        if (! is_array($fields) || $fields === []) {
                            return [];
                        }

                        return array_map(fn (string $field) => [
                            'intent' => $intent,
                            'field' => $field,
                            'message' => $log->message,
                        ], $fields);
                    });
            })
            ->groupBy(fn (array $row) => $row['intent'].'|'.$row['field'])
            ->map(function (Collection $group) {
                $first = $group->first();

                return [
                    'priority' => 'medium',
                    'reason' => 'Campo pendente aparece com frequencia e merece fixture de follow-up',
                    'message' => $first['message'],
                    'intent' => $first['intent'],
                    'count' => $group->count(),
                    'suggested_example' => [
                        'message' => $first['message'],
                        'expected_intent' => $first['intent'],
                        'expected_missing_field' => $first['field'],
                    ],
                ];
            })
            ->sortByDesc('count')
            ->take(5)
            ->values();

        $items = $items->merge($missingFieldHotspots);

        return $items
            ->sortByDesc(fn (array $item) => ($item['priority'] === 'high' ? 1000 : 100) + $item['count'])
            ->values()
            ->all();
    }

    private function assistantIntent(WhatsAppConversationLog $log): string
    {
        return (string) (data_get($log->metadata, 'assistant_intent')
            ?? $log->classification
            ?? 'unknown');
    }
}
