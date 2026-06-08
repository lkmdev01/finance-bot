<?php

namespace App\Assistant\Reports;

use App\Models\WhatsAppConversationLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class AssistantObservabilityService
{
    public function summary(int $days = 14, int $sampleSize = 1000): array
    {
        $logs = WhatsAppConversationLog::query()
            ->latest('id')
            ->where('created_at', '>=', now()->subDays($days))
            ->limit($sampleSize)
            ->get();

        $regressionBacklog = $this->buildRegressionBacklog($logs);

        return [
            'period_days' => $days,
            'sample_size' => $logs->count(),
            'totals' => $this->buildTotals($logs),
            'by_intent' => $this->buildIntentBreakdown($logs),
            'recent_failures' => $this->buildRecentFailures($logs),
            'top_unknown_messages' => $this->buildTopUnknownMessages($logs),
            'regression_backlog' => $regressionBacklog,
            'regression_backlog_by_domain' => $this->groupRegressionBacklogByDomain($regressionBacklog),
        ];
    }

    public function fixtureExport(int $days = 14, int $sampleSize = 1000, string $focus = 'all', ?string $domain = null): string
    {
        $items = $this->filteredRegressionBacklog($days, $sampleSize, $focus, $domain)
            ->map(fn (array $item) => $item['suggested_example'])
            ->values()
            ->all();

        return "<?php\n\nreturn ".var_export($items, true).";\n";
    }

    public function syncFixtureFiles(
        int $days = 14,
        int $sampleSize = 1000,
        string $focus = 'all',
        ?string $outputDirectory = null,
        ?string $domain = null
    ): array {
        $outputDirectory ??= base_path('tests/Fixtures/generated');
        File::ensureDirectoryExists($outputDirectory);

        $domains = $domain !== null && $domain !== ''
            ? [$domain]
            : array_keys($this->summary($days, $sampleSize)['regression_backlog_by_domain'] ?? []);
        $written = [];

        foreach ($domains as $domain) {
            $content = $this->fixtureExport($days, $sampleSize, $focus, $domain);
            if (trim($content) === "<?php\n\nreturn array (\n);\n") {
                continue;
            }

            $domainDirectory = rtrim($outputDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$this->fixtureDirectoryName($domain);
            File::ensureDirectoryExists($domainDirectory);
            $path = $domainDirectory.DIRECTORY_SEPARATOR.$this->fixtureFileName($domain);
            file_put_contents($path, $content);
            $written[$domain] = $path;
        }

        return $written;
    }

    public function previewFixtureChanges(
        int $days = 14,
        int $sampleSize = 1000,
        string $focus = 'all',
        ?string $domain = null,
        ?string $outputDirectory = null
    ): array {
        $outputDirectory ??= base_path('tests/Fixtures/generated');

        $domains = $domain !== null && $domain !== ''
            ? [$domain]
            : array_keys($this->summary($days, $sampleSize)['regression_backlog_by_domain'] ?? []);

        $preview = [];

        foreach ($domains as $domain) {
            $domainDirectory = rtrim($outputDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$this->fixtureDirectoryName($domain);
            $path = $domainDirectory.DIRECTORY_SEPARATOR.$this->fixtureFileName($domain);
            $generated = $this->fixtureExport($days, $sampleSize, $focus, $domain);
            $current = File::exists($path) ? File::get($path) : null;

            $preview[$domain] = [
                'domain' => $domain,
                'path' => $path,
                'exists' => $current !== null,
                'has_backlog' => trim($generated) !== "<?php\n\nreturn array (\n);\n",
                'has_changes' => $current !== $generated,
                'current_content' => $current,
                'generated_content' => $generated,
                'diff' => $this->buildFixtureDiff($current, $generated),
            ];
        }

        return $preview;
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
                    'domain' => $this->inferDomainFromLog($group->first()),
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
                            'domain' => $this->inferDomainFromLog($log),
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
                    'domain' => $first['domain'] ?? $this->inferDomainFromIntent($first['intent'] ?? null),
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

    private function filteredRegressionBacklog(int $days, int $sampleSize, string $focus, ?string $domain): Collection
    {
        $summary = $this->summary($days, $sampleSize);

        return collect($summary['regression_backlog'])
            ->filter(function (array $item) use ($focus) {
                return match ($focus) {
                    'unknown' => ($item['intent'] ?? null) === 'unknown',
                    'missing' => ($item['intent'] ?? null) !== 'unknown',
                    default => true,
                };
            })
            ->filter(function (array $item) use ($domain) {
                return $domain === null || ($item['domain'] ?? 'unknown') === $domain;
            })
            ->values();
    }

    private function groupRegressionBacklogByDomain(array $items): array
    {
        return collect($items)
            ->groupBy(fn (array $item) => $item['domain'] ?? 'unknown')
            ->map(fn (Collection $group) => $group->values()->all())
            ->sortKeys()
            ->all();
    }

    private function inferDomainFromLog(?WhatsAppConversationLog $log): string
    {
        if (! $log) {
            return 'unknown';
        }

        return (string) (data_get($log->metadata, 'assistant_domain')
            ?? data_get($log->metadata, 'domain')
            ?? $this->inferDomainFromIntent($this->assistantIntent($log)));
    }

    private function inferDomainFromIntent(?string $intent): string
    {
        return match ($intent) {
            'create_note', 'query_notes', 'update_note', 'delete_note' => 'notes',
            'create_reminder', 'query_reminders', 'update_reminder', 'delete_reminder' => 'reminders',
            'create_drive_file', 'query_drive_files' => 'drive',
            'create_budget', 'query_budgets' => 'budget',
            'create_goal', 'query_savings', 'update_savings_goal',
            'create_subscription', 'query_subscriptions', 'update_subscription', 'cancel_subscription' => 'planning',
            'create_recurring_transaction', 'update_recurring_transaction', 'cancel_recurring_transaction',
            'create_expense', 'create_income', 'query_balance', 'query_category_spending', 'query_month_report', 'update_transaction', 'delete_transaction', 'list_transactions' => 'transaction',
            'unknown', null, '' => 'unknown',
            default => 'assistant',
        };
    }

    private function fixtureFileName(string $domain): string
    {
        $safe = preg_replace('/[^a-z0-9_]+/i', '_', strtolower($domain)) ?: 'unknown';

        return "assistant_observability_{$safe}_examples.php";
    }

    private function fixtureDirectoryName(string $domain): string
    {
        return preg_replace('/[^a-z0-9_]+/i', '-', strtolower($domain)) ?: 'unknown';
    }

    private function buildFixtureDiff(?string $current, string $generated): string
    {
        if ($current === $generated) {
            return "Sem alteracoes.\n";
        }

        $currentLines = $current !== null ? preg_split('/\r\n|\r|\n/', $current) ?: [] : [];
        $generatedLines = preg_split('/\r\n|\r|\n/', $generated) ?: [];
        $max = max(count($currentLines), count($generatedLines));
        $lines = ['--- atual', '+++ gerado'];

        for ($index = 0; $index < $max; $index++) {
            $before = $currentLines[$index] ?? null;
            $after = $generatedLines[$index] ?? null;

            if ($before === $after) {
                if ($before !== null) {
                    $lines[] = ' '.$before;
                }

                continue;
            }

            if ($before !== null) {
                $lines[] = '-'.$before;
            }

            if ($after !== null) {
                $lines[] = '+'.$after;
            }
        }

        return implode("\n", $lines)."\n";
    }
}
