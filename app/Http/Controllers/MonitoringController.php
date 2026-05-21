<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversationLog;
use App\Services\PerformanceMetricsService;
use Illuminate\View\View;

class MonitoringController extends Controller
{
    public function index(PerformanceMetricsService $metricsService): View
    {
        return view('pages.monitoring.index', [
            'metrics' => $metricsService,
            'messagesToday' => WhatsAppContact::whereDate('updated_at', today())->count(),
            'messagesThisWeek' => WhatsAppContact::whereBetween('updated_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
            'totalContacts' => WhatsAppContact::count(),
            'recentErrors' => AuditLog::where('action', 'error')->latest()->limit(5)->get(),
            'recentConversationLogs' => WhatsAppConversationLog::query()->latest()->limit(20)->get(),
        ]);
    }
}
