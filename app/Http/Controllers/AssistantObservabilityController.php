<?php

namespace App\Http\Controllers;

use App\Assistant\Reports\AssistantObservabilityService;
use Illuminate\Http\Request;

class AssistantObservabilityController extends Controller
{
    public function __invoke(Request $request, AssistantObservabilityService $observabilityService)
    {
        $days = max(1, min(30, (int) $request->integer('days', 14)));
        $approvedDays = max(1, min(30, (int) $request->integer('approved_days', 7)));
        $focus = (string) $request->query('focus', 'all');
        $source = (string) $request->query('source', 'all');
        $previewDomain = $request->query('preview_domain');
        $previewItem = $request->query('preview_item');

        return view('pages.assistant.observability', [
            'summary' => $observabilityService->summary($days),
            'days' => $days,
            'approvedDays' => $approvedDays,
            'focus' => $focus,
            'source' => $source,
            'sourceOptions' => $observabilityService->approvalSources(),
            'previewDomain' => is_string($previewDomain) && $previewDomain !== '' ? $previewDomain : null,
            'previewItemKey' => is_string($previewItem) && $previewItem !== '' ? $previewItem : null,
            'fixturePreview' => is_string($previewDomain) && $previewDomain !== ''
                ? ($observabilityService->previewFixtureChanges($days, 1000, $focus, $previewDomain)[$previewDomain] ?? null)
                : null,
            'fixtureItemPreview' => is_string($previewItem) && $previewItem !== ''
                ? $observabilityService->previewFixtureItem($days, 1000, $focus, $previewItem)
                : null,
            'weeklyReviewUsage' => $observabilityService->weeklyReviewUsage($approvedDays, $source),
            'weeklyReviewTrend' => $observabilityService->weeklyReviewTrend(6, $source),
        ]);
    }

    public function syncFixtures(Request $request, AssistantObservabilityService $observabilityService)
    {
        $days = max(1, min(30, (int) $request->integer('days', 14)));
        $focus = (string) $request->input('focus', 'all');
        $domain = $request->input('domain');
        $itemKey = $request->input('item_key');

        if (is_string($itemKey) && $itemKey !== '') {
            $synced = $observabilityService->syncFixtureItem(
                days: $days,
                sampleSize: 1000,
                focus: $focus,
                itemKey: $itemKey,
            );

            $message = $synced === null
                ? 'Nao encontrei o item selecionado para sincronizar.'
                : 'Fixture sincronizada para o item selecionado em '.$synced['domain'].'.';

            if ($synced !== null) {
                $observabilityService->recordApprovalActivity([$synced['item']], 'dashboard_item');
                $observabilityService->recordSyncActivity('item', [
                    'days' => $days,
                    'focus' => $focus,
                    'domain' => $synced['domain'],
                    'item_key' => $itemKey,
                ]);
            }

            return redirect()
                ->route('assistant.observability', ['days' => $days, 'focus' => $focus])
                ->with('message', $message);
        }

        $approvedItems = $observabilityService->backlogItems(
            days: $days,
            sampleSize: 1000,
            focus: $focus,
            domain: is_string($domain) && $domain !== '' ? $domain : null,
        );

        $written = $observabilityService->syncFixtureFiles(
            days: $days,
            sampleSize: 1000,
            focus: $focus,
            domain: is_string($domain) && $domain !== '' ? $domain : null,
        );

        if ($written !== []) {
            $observabilityService->recordApprovalActivity($approvedItems, is_string($domain) && $domain !== '' ? 'dashboard_domain' : 'dashboard_all');
            $observabilityService->recordSyncActivity(is_string($domain) && $domain !== '' ? 'domain' : 'all', [
                'days' => $days,
                'focus' => $focus,
                'domains' => array_keys($written),
                'item_count' => count($approvedItems),
            ]);
        }

        $message = $written === []
            ? 'Nenhum backlog elegivel para sincronizar em fixtures.'
            : 'Fixtures sincronizadas: '.implode(', ', array_keys($written));

        return redirect()
            ->route('assistant.observability', ['days' => $days, 'focus' => $focus])
            ->with('message', $message);
    }

    public function exportFixtures(Request $request, AssistantObservabilityService $observabilityService)
    {
        $days = max(1, min(30, (int) $request->integer('days', 14)));
        $approvedDays = max(1, min(30, (int) $request->integer('approved_days', 7)));
        $focus = (string) $request->query('focus', 'all');
        $source = (string) $request->query('source', 'all');
        $domain = $request->query('domain');
        $itemKey = $request->query('item_key');
        $approved = $request->boolean('approved');
        $filename = $domain
            ? "assistant_observability_{$domain}_fixtures.php"
            : 'assistant_observability_fixtures.php';

        if (is_string($itemKey) && $itemKey !== '') {
            $content = $observabilityService->itemFixtureExport($days, 1000, $focus, $itemKey);

            abort_if($content === null, 404);

            return response(
                $content,
                200,
                [
                    'Content-Type' => 'text/plain; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename="assistant_observability_item_fixture.php"',
                ]
            );
        }

        if ($approved) {
            $approvedFilename = $domain
                ? "assistant_observability_approved_{$domain}_fixtures.php"
                : 'assistant_observability_approved_fixtures.php';

            return response(
                $observabilityService->approvedFixtureExport(
                    $approvedDays,
                    is_string($domain) && $domain !== '' ? $domain : null,
                    $source
                ),
                200,
                [
                    'Content-Type' => 'text/plain; charset=UTF-8',
                    'Content-Disposition' => "attachment; filename=\"{$approvedFilename}\"",
                ]
            );
        }

        return response(
            $observabilityService->fixtureExport($days, 1000, $focus, is_string($domain) && $domain !== '' ? $domain : null),
            200,
            [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]
        );
    }
}
