<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailLogController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        return view('pages.admin.email-logs.index', [
            'search' => $search,
            'logs' => EmailLog::query()
                ->with('user')
                ->when($search !== '', function ($query) use ($search) {
                    $query->where(function ($query) use ($search) {
                        $query->where('to_email', 'like', "%{$search}%")
                            ->orWhere('subject', 'like', "%{$search}%")
                            ->orWhere('notification_type', 'like', "%{$search}%");
                    });
                })
                ->latest()
                ->paginate(50)
                ->withQueryString(),
            'stats' => [
                'today' => EmailLog::query()->whereDate('created_at', today())->count(),
                'week' => EmailLog::query()->where('created_at', '>=', now()->subDays(7))->count(),
                'total' => EmailLog::query()->count(),
            ],
        ]);
    }
}
