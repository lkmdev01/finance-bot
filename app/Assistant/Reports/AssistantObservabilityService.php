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

        return $this->renderFixtureContent($items);
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

    public function previewFixtureItem(
        int $days = 14,
        int $sampleSize = 1000,
        string $focus = 'all',
        string $itemKey,
        ?string $outputDirectory = null
    ): ?array {
        $item = $this->findRegressionBacklogItem($days, $sampleSize, $focus, $itemKey);
        if ($item === null) {
            return null;
        }

        $outputDirectory ??= base_path('tests/Fixtures/generated');
        $domain = $item['domain'] ?? 'unknown';
        $path = $this->fixturePathForDomain($domain, $outputDirectory);
        $current = File::exists($path) ? File::get($path) : null;
        $existingExamples = $this->loadFixtureExamples($path);
        $mergedExamples = $this->mergeSuggestedExamples($existingExamples, [$item['suggested_example']]);
        $generated = $this->renderFixtureContent($mergedExamples);

        return [
            'domain' => $domain,
            'item_key' => $item['key'],
            'path' => $path,
            'exists' => $current !== null,
            'has_backlog' => true,
            'has_changes' => $current !== $generated,
            'current_content' => $current,
            'generated_content' => $generated,
            'diff' => $this->buildFixtureDiff($current, $generated),
            'item' => $item,
        ];
    }

    public function syncFixtureItem(
        int $days = 14,
        int $sampleSize = 1000,
        string $focus = 'all',
        string $itemKey,
        ?string $outputDirectory = null
    ): ?array {
        $preview = $this->previewFixtureItem($days, $sampleSize, $focus, $itemKey, $outputDirectory);
        if ($preview === null) {
            return null;
        }

        $directory = dirname($preview['path']);
        File::ensureDirectoryExists($directory);
        file_put_contents($preview['path'], $preview['generated_content']);

        return [
            'domain' => $preview['domain'],
            'path' => $preview['path'],
            'item' => $preview['item'],
        ];
    }

    public function itemFixtureExport(int $days = 14, int $sampleSize = 1000, string $focus = 'all', string $itemKey = ''): ?string
    {
        $item = $this->findRegressionBacklogItem($days, $sampleSize, $focus, $itemKey);

        return $item === null
            ? null
            : $this->renderFixtureContent([$item['suggested_example']]);
    }

    public function backlogItems(int $days = 14, int $sampleSize = 1000, string $focus = 'all', ?string $domain = null): array
    {
        return $this->filteredRegressionBacklog($days, $sampleSize, $focus, $domain)
            ->values()
            ->all();
    }

    public function recordReviewRun(array $payload = []): void
    {
        $this->appendActivity(array_merge([
            'type' => 'weekly_review_run',
        ], $payload));
    }

    public function recordSyncActivity(string $mode, array $payload = []): void
    {
        $this->appendActivity(array_merge([
            'type' => 'fixture_sync',
            'mode' => $mode,
            'source' => $payload['source'] ?? $mode,
        ], $payload));
    }

    public function recordApprovalActivity(array $items, string $source = 'dashboard'): void
    {
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $this->appendActivity([
                'type' => 'fixture_item_approved',
                'source' => $source,
                'occurred_at' => $item['occurred_at'] ?? null,
                'item_key' => $item['key'] ?? null,
                'domain' => $item['domain'] ?? 'unknown',
                'intent' => $item['intent'] ?? 'unknown',
                'message' => $item['message'] ?? '',
                'suggested_example' => $item['suggested_example'] ?? null,
            ]);
        }
    }

    public function weeklyReviewUsage(int $days = 7, ?string $source = null): array
    {
        $activities = $this->recentActivities($days);
        $reviewRuns = $activities->where('type', 'weekly_review_run')->values();
        $syncs = $this->filterActivitiesBySource(
            $activities->where('type', 'fixture_sync')->values(),
            $source
        );
        $approvals = $this->filterActivitiesBySource(
            $activities->where('type', 'fixture_item_approved')->values(),
            $source
        );

        return [
            'days' => $days,
            'source' => $source,
            'review_runs' => $reviewRuns->count(),
            'synced_review_runs' => $reviewRuns->where('sync', true)->count(),
            'sync_runs' => $syncs->count(),
            'item_approvals' => $approvals->count(),
            'approved_domains' => $approvals->pluck('domain')->filter()->unique()->values()->all(),
            'approvals_by_domain' => $approvals
                ->groupBy('domain')
                ->map(fn (Collection $group, string $domain) => [
                    'domain' => $domain,
                    'count' => $group->count(),
                    'last_approved_at' => $group->sortByDesc('occurred_at')->first()['occurred_at'] ?? null,
                ])
                ->sortByDesc('count')
                ->values()
                ->all(),
            'last_review_run_at' => $reviewRuns->sortByDesc('occurred_at')->first()['occurred_at'] ?? null,
            'last_approval_at' => $approvals->sortByDesc('occurred_at')->first()['occurred_at'] ?? null,
        ];
    }

    public function approvedFixtureExport(int $days = 7, ?string $domain = null, ?string $source = null): string
    {
        $examples = $this->filterActivitiesBySource(
            $this->recentActivities($days)
            ->where('type', 'fixture_item_approved')
            ->filter(function (array $activity) use ($domain) {
                return $domain === null || ($activity['domain'] ?? 'unknown') === $domain;
            }),
            $source
        )
            ->pluck('suggested_example')
            ->filter(fn ($example) => is_array($example))
            ->values()
            ->all();

        return $this->renderFixtureContent($this->mergeSuggestedExamples([], $examples));
    }

    public function weeklyReviewTrend(int $weeks = 6, ?string $source = null): array
    {
        $weeks = max(2, min(12, $weeks));
        $start = now()->startOfWeek()->subWeeks($weeks - 1);
        $activities = $this->recentActivities(7 * $weeks + 7);
        $approvals = $this->filterActivitiesBySource(
            $activities->where('type', 'fixture_item_approved')->values(),
            $source
        );
        $syncs = $this->filterActivitiesBySource(
            $activities->where('type', 'fixture_sync')->values(),
            $source
        );
        $reviewRuns = $activities->where('type', 'weekly_review_run')->values();

        $rows = [];

        for ($index = 0; $index < $weeks; $index++) {
            $weekStart = $start->copy()->addWeeks($index);
            $weekEnd = $weekStart->copy()->endOfWeek();
            $label = $weekStart->format('d/m');

            $weekApprovals = $approvals->filter(fn (array $item) => $this->activityWithinWeek($item, $weekStart, $weekEnd));
            $weekSyncs = $syncs->filter(fn (array $item) => $this->activityWithinWeek($item, $weekStart, $weekEnd));
            $weekRuns = $reviewRuns->filter(fn (array $item) => $this->activityWithinWeek($item, $weekStart, $weekEnd));

            $rows[] = [
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekEnd->toDateString(),
                'label' => $label,
                'review_runs' => $weekRuns->count(),
                'sync_runs' => $weekSyncs->count(),
                'item_approvals' => $weekApprovals->count(),
            ];
        }

        return [
            'weeks' => $weeks,
            'source' => $source,
            'series' => $rows,
            'totals' => [
                'review_runs' => array_sum(array_column($rows, 'review_runs')),
                'sync_runs' => array_sum(array_column($rows, 'sync_runs')),
                'item_approvals' => array_sum(array_column($rows, 'item_approvals')),
            ],
        ];
    }

    public function approvalSources(): array
    {
        return [
            'all' => 'Todas as origens',
            'dashboard_item' => 'Dashboard: item',
            'dashboard_domain' => 'Dashboard: dominio',
            'dashboard_all' => 'Dashboard: tudo',
            'weekly_review' => 'Weekly review',
        ];
    }

    public function weeklyGoals(): array
    {
        $settings = app(\App\Services\AssistantOperationsSettingsService::class)->current();

        return [
            'review_runs' => max(0, (int) ($settings['weekly_goal_review_runs'] ?? config('assistant.weekly_goals.review_runs', 1))),
            'item_approvals' => max(0, (int) ($settings['weekly_goal_item_approvals'] ?? config('assistant.weekly_goals.item_approvals', 10))),
            'sync_runs' => max(0, (int) ($settings['weekly_goal_sync_runs'] ?? config('assistant.weekly_goals.sync_runs', 1))),
        ];
    }

    public function weeklyOperationalSnapshot(?string $source = null): array
    {
        $trend = $this->weeklyReviewTrend(2, $source);
        $currentWeek = $trend['series'][1] ?? $trend['series'][0] ?? [
            'review_runs' => 0,
            'sync_runs' => 0,
            'item_approvals' => 0,
        ];
        $previousWeek = $trend['series'][0] ?? [
            'review_runs' => 0,
            'sync_runs' => 0,
            'item_approvals' => 0,
        ];
        $goals = $this->weeklyGoals();
        $lastReviewAt = $this->weeklyReviewUsage(14, $source)['last_review_run_at'] ?? null;

        return [
            'source' => $source,
            'goals' => [
                'review_runs' => [
                    'target' => $goals['review_runs'],
                    'current' => $currentWeek['review_runs'] ?? 0,
                    'remaining' => max(0, $goals['review_runs'] - ($currentWeek['review_runs'] ?? 0)),
                    'met' => ($currentWeek['review_runs'] ?? 0) >= $goals['review_runs'],
                ],
                'item_approvals' => [
                    'target' => $goals['item_approvals'],
                    'current' => $currentWeek['item_approvals'] ?? 0,
                    'remaining' => max(0, $goals['item_approvals'] - ($currentWeek['item_approvals'] ?? 0)),
                    'met' => ($currentWeek['item_approvals'] ?? 0) >= $goals['item_approvals'],
                ],
                'sync_runs' => [
                    'target' => $goals['sync_runs'],
                    'current' => $currentWeek['sync_runs'] ?? 0,
                    'remaining' => max(0, $goals['sync_runs'] - ($currentWeek['sync_runs'] ?? 0)),
                    'met' => ($currentWeek['sync_runs'] ?? 0) >= $goals['sync_runs'],
                ],
            ],
            'comparison' => [
                'review_runs' => $this->buildTrendDelta($currentWeek['review_runs'] ?? 0, $previousWeek['review_runs'] ?? 0),
                'sync_runs' => $this->buildTrendDelta($currentWeek['sync_runs'] ?? 0, $previousWeek['sync_runs'] ?? 0),
                'item_approvals' => $this->buildTrendDelta($currentWeek['item_approvals'] ?? 0, $previousWeek['item_approvals'] ?? 0),
            ],
            'sla' => $this->buildWeeklySlaStatus($currentWeek, $lastReviewAt, $goals),
            'alerts' => $this->buildWeeklyOperationalAlerts(
                currentWeek: $currentWeek,
                lastReviewAt: $lastReviewAt,
                source: $source,
                goals: $goals
            ),
        ];
    }

    public function weeklyReviewNow(int $days = 7, int $sample = 1000, string $focus = 'all', bool $sync = true): array
    {
        $exitCode = \Illuminate\Support\Facades\Artisan::call('assistant:weekly-review', [
            '--days' => $days,
            '--sample' => $sample,
            '--focus' => $focus,
            '--sync' => $sync,
        ]);

        return [
            'exit_code' => $exitCode,
            'output' => trim(\Illuminate\Support\Facades\Artisan::output()),
        ];
    }

    public function renderWeeklySlaAdminMessage(?string $source = null): string
    {
        $snapshot = $this->weeklyOperationalSnapshot($source);
        $usage = $this->weeklyReviewUsage(7, $source);
        $sla = $snapshot['sla'] ?? ['label' => 'SLA em atencao'];
        $goals = $snapshot['goals'] ?? [];
        $observabilityUrl = rtrim((string) config('app.url'), '/').'/assistant/observability';

        return trim(sprintf(
            "Resumo semanal do assistente\nSLA: %s\n\nRevisoes: %d/%d\nSyncs: %d/%d\nAprovacoes: %d/%d\nDominios: %d\n\nUltima revisao: %s\n\nAbrir observabilidade:\n%s",
            $sla['label'] ?? 'SLA em atencao',
            (int) ($goals['review_runs']['current'] ?? 0),
            (int) ($goals['review_runs']['target'] ?? 0),
            (int) ($goals['sync_runs']['current'] ?? 0),
            (int) ($goals['sync_runs']['target'] ?? 0),
            (int) ($goals['item_approvals']['current'] ?? 0),
            (int) ($goals['item_approvals']['target'] ?? 0),
            count($usage['approved_domains'] ?? []),
            $usage['last_review_run_at'] ?? 'nao registrada',
            $observabilityUrl
        ));
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
                    'key' => $this->buildBacklogItemKey([
                        'intent' => 'unknown',
                        'domain' => $this->inferDomainFromLog($group->first()),
                        'message' => $message,
                    ]),
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
                    'key' => $this->buildBacklogItemKey([
                        'intent' => $first['intent'],
                        'domain' => $first['domain'] ?? $this->inferDomainFromIntent($first['intent'] ?? null),
                        'field' => $first['field'],
                        'message' => $first['message'],
                    ]),
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

    private function findRegressionBacklogItem(int $days, int $sampleSize, string $focus, string $itemKey): ?array
    {
        return $this->filteredRegressionBacklog($days, $sampleSize, $focus, null)
            ->first(fn (array $item) => ($item['key'] ?? null) === $itemKey);
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

    private function fixturePathForDomain(string $domain, string $outputDirectory): string
    {
        $domainDirectory = rtrim($outputDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$this->fixtureDirectoryName($domain);

        return $domainDirectory.DIRECTORY_SEPARATOR.$this->fixtureFileName($domain);
    }

    private function renderFixtureContent(array $items): string
    {
        return "<?php\n\nreturn ".var_export(array_values($items), true).";\n";
    }

    private function activityLogPath(): string
    {
        return storage_path('app/assistant/weekly_review_activity.jsonl');
    }

    private function appendActivity(array $event): void
    {
        $path = $this->activityLogPath();
        File::ensureDirectoryExists(dirname($path));

        $event['occurred_at'] = $event['occurred_at'] ?? now()->toIso8601String();

        File::append($path, json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    private function recentActivities(int $days): Collection
    {
        $path = $this->activityLogPath();
        if (! File::exists($path)) {
            return collect();
        }

        return collect(preg_split('/\r\n|\r|\n/', File::get($path)) ?: [])
            ->filter(fn (string $line) => trim($line) !== '')
            ->map(function (string $line) {
                $decoded = json_decode($line, true);

                return is_array($decoded) ? $decoded : null;
            })
            ->filter(fn ($entry) => is_array($entry))
            ->filter(function (array $entry) use ($days) {
                $occurredAt = $entry['occurred_at'] ?? null;
                if (! is_string($occurredAt) || $occurredAt === '') {
                    return false;
                }

                return now()->subDays($days)->lte(\Illuminate\Support\Carbon::parse($occurredAt));
            })
            ->values();
    }

    private function filterActivitiesBySource(Collection $activities, ?string $source): Collection
    {
        if ($source === null || $source === '' || $source === 'all') {
            return $activities->values();
        }

        return $activities
            ->filter(fn (array $activity) => ($activity['source'] ?? null) === $source)
            ->values();
    }

    private function activityWithinWeek(array $activity, \Illuminate\Support\Carbon $weekStart, \Illuminate\Support\Carbon $weekEnd): bool
    {
        $occurredAt = $activity['occurred_at'] ?? null;
        if (! is_string($occurredAt) || $occurredAt === '') {
            return false;
        }

        $timestamp = \Illuminate\Support\Carbon::parse($occurredAt);

        return $timestamp->betweenIncluded($weekStart, $weekEnd);
    }

    private function buildTrendDelta(int $current, int $previous): array
    {
        return [
            'current' => $current,
            'previous' => $previous,
            'delta' => $current - $previous,
            'direction' => $current <=> $previous,
        ];
    }

    private function buildWeeklyOperationalAlerts(array $currentWeek, ?string $lastReviewAt, ?string $source, array $goals): array
    {
        $alerts = [];
        $cta = $this->observabilityCta($source);

        if (($currentWeek['review_runs'] ?? 0) < $goals['review_runs']) {
            $alerts[] = [
                'tone' => 'warning',
                'title' => 'Revisao semanal pendente',
                'text' => 'A revisao operacional desta semana ainda nao bateu a meta minima.',
                'cta' => $cta,
            ];
        }

        if (($currentWeek['sync_runs'] ?? 0) < $goals['sync_runs']) {
            $alerts[] = [
                'tone' => 'warning',
                'title' => 'Sync semanal pendente',
                'text' => 'Ainda nao houve sync suficiente de fixtures nesta semana.',
                'cta' => $cta,
            ];
        }

        if (($currentWeek['item_approvals'] ?? 0) < $goals['item_approvals']) {
            $alerts[] = [
                'tone' => 'info',
                'title' => 'Meta de aprovacoes em aberto',
                'text' => 'Ainda faltam aprovacoes para fechar a meta semanal do assistente.',
                'cta' => $cta,
            ];
        }

        if ($lastReviewAt !== null && \Illuminate\Support\Carbon::parse($lastReviewAt)->lt(now()->subDays(7))) {
            $alerts[] = [
                'tone' => 'warning',
                'title' => 'Revisao atrasada',
                'text' => 'A ultima revisao registrada passou de 7 dias e precisa ser retomada.',
                'cta' => $cta,
            ];
        }

        if ($alerts === []) {
            $alerts[] = [
                'tone' => 'ok',
                'title' => 'Ritual semanal em dia',
                'text' => 'Revisao, sync e aprovacoes estao dentro da meta nesta semana.',
                'cta' => $cta,
            ];
        }

        return $alerts;
    }

    private function buildWeeklySlaStatus(array $currentWeek, ?string $lastReviewAt, array $goals): array
    {
        $meetsReview = ($currentWeek['review_runs'] ?? 0) >= $goals['review_runs'];
        $meetsSync = ($currentWeek['sync_runs'] ?? 0) >= $goals['sync_runs'];
        $meetsApprovals = ($currentWeek['item_approvals'] ?? 0) >= $goals['item_approvals'];
        $reviewLate = $lastReviewAt !== null && \Illuminate\Support\Carbon::parse($lastReviewAt)->lt(now()->subDays(7));

        $score = 0;
        $score += $meetsReview ? 1 : 0;
        $score += $meetsSync ? 1 : 0;
        $score += $meetsApprovals ? 1 : 0;
        $score += $reviewLate ? 0 : 1;

        $status = match (true) {
            $score >= 4 => 'green',
            $score >= 2 => 'yellow',
            default => 'red',
        };

        $label = match ($status) {
            'green' => 'SLA saudavel',
            'yellow' => 'SLA em atencao',
            default => 'SLA critico',
        };

        return [
            'status' => $status,
            'label' => $label,
            'score' => $score,
            'checks' => [
                'review_runs' => $meetsReview,
                'sync_runs' => $meetsSync,
                'item_approvals' => $meetsApprovals,
                'recent_review' => ! $reviewLate,
            ],
        ];
    }

    private function observabilityCta(?string $source): array
    {
        $params = [
            'days' => 14,
            'approved_days' => 7,
        ];

        if ($source !== null && $source !== '' && $source !== 'all') {
            $params['source'] = $source;
        }

        return [
            'label' => 'Abrir ritual',
            'route' => route('assistant.observability', $params),
        ];
    }

    private function loadFixtureExamples(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $examples = include $path;

        return is_array($examples) ? array_values($examples) : [];
    }

    private function mergeSuggestedExamples(array $existingExamples, array $newExamples): array
    {
        $merged = [];
        $seen = [];

        foreach (array_merge($existingExamples, $newExamples) as $example) {
            $key = md5(var_export($example, true));
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $merged[] = $example;
        }

        return $merged;
    }

    private function buildBacklogItemKey(array $payload): string
    {
        return sha1(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: serialize($payload));
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
