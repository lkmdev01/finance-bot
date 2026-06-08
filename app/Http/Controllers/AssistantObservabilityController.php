<?php

namespace App\Http\Controllers;

use App\Assistant\Reports\AssistantObservabilityService;
use Illuminate\Http\Request;

class AssistantObservabilityController extends Controller
{
    public function __invoke(Request $request, AssistantObservabilityService $observabilityService)
    {
        $days = max(1, min(30, (int) $request->integer('days', 14)));
        $focus = (string) $request->query('focus', 'all');

        return view('pages.assistant.observability', [
            'summary' => $observabilityService->summary($days),
            'days' => $days,
            'focus' => $focus,
        ]);
    }

    public function syncFixtures(Request $request, AssistantObservabilityService $observabilityService)
    {
        $days = max(1, min(30, (int) $request->integer('days', 14)));
        $focus = (string) $request->input('focus', 'all');
        $domain = $request->input('domain');

        $written = $observabilityService->syncFixtureFiles(
            days: $days,
            sampleSize: 1000,
            focus: $focus,
            domain: is_string($domain) && $domain !== '' ? $domain : null,
        );

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
        $focus = (string) $request->query('focus', 'all');
        $domain = $request->query('domain');
        $filename = $domain
            ? "assistant_observability_{$domain}_fixtures.php"
            : 'assistant_observability_fixtures.php';

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
