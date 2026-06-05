<?php

namespace App\Http\Controllers;

use App\Assistant\Reports\AssistantObservabilityService;
use Illuminate\Http\Request;

class AssistantObservabilityController extends Controller
{
    public function __invoke(Request $request, AssistantObservabilityService $observabilityService)
    {
        $days = max(1, min(30, (int) $request->integer('days', 14)));

        return view('pages.assistant.observability', [
            'summary' => $observabilityService->summary($days),
            'days' => $days,
        ]);
    }
}
