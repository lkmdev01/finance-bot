<?php

namespace App\Http\Controllers\Admin;

use App\Assistant\Reports\AssistantObservabilityService;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(AssistantObservabilityService $service): View
    {
        return view('pages.admin.dashboard', [
            'assistantWeeklyUsage' => $service->weeklyReviewUsage(28),
            'assistantWeeklyTrend' => $service->weeklyReviewTrend(4),
            'assistantWeeklySnapshot' => $service->weeklyOperationalSnapshot(),
        ]);
    }
}
