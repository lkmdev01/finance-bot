<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AbacatePaySubscription;
use App\Models\User;
use App\Models\WhatsAppConversationLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class BetaDashboardController extends Controller
{
    /**
     * @return array<string, string>
     */
    public static function statuses(): array
    {
        return [
            'candidate' => 'Candidato',
            'invited' => 'Convidado',
            'active' => 'Ativo',
            'watch' => 'Acompanhar',
            'paused' => 'Pausado',
            'done' => 'Concluido',
        ];
    }

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $filter = (string) $request->query('filter', 'all');

        $users = User::query()
            ->withCount(['transactions', 'driveFiles', 'notes'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone_number', 'like', "%{$search}%");
                });
            })
            ->when($filter === 'beta', fn ($query) => $query->whereNotNull('beta_status'))
            ->when($filter === 'whatsapp_missing', fn ($query) => $query->whereNull('whatsapp_verified_at'))
            ->when($filter === 'payment_issue', function ($query) {
                $query->whereNotNull('billing_plan_code')
                    ->where(function ($query) {
                        $query->whereNull('billing_access_ends_at')
                            ->orWhere('billing_access_ends_at', '<', now())
                            ->orWhereIn('billing_plan_status', ['pending', 'cancelled']);
                    });
            })
            ->when($filter === 'active_paid', function ($query) {
                $query->whereNotNull('billing_plan_code')
                    ->whereIn('billing_plan_status', ['active', 'renewed', 'cancelled'])
                    ->where('billing_access_ends_at', '>', now());
            })
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $userIds = $users->getCollection()->pluck('id');

        return view('pages.admin.beta.index', [
            'users' => $users,
            'statuses' => self::statuses(),
            'filter' => $filter,
            'search' => $search,
            'summary' => $this->summary(),
            'latestLogs' => $this->latestLogsFor($userIds),
            'recentErrorCounts' => $this->recentErrorCountsFor($userIds),
            'latestSubscriptions' => $this->latestSubscriptionsFor($userIds),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'beta_status' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::statuses()))],
            'beta_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $status = $validated['beta_status'] ?: null;

        $user->forceFill([
            'beta_status' => $status,
            'beta_notes' => $validated['beta_notes'] ?: null,
            'beta_invited_at' => $status === 'invited' && blank($user->beta_invited_at)
                ? now()
                : $user->beta_invited_at,
        ])->save();

        return back()->with('message', "Beta atualizado para {$user->name}.");
    }

    /**
     * @return array<string, int>
     */
    private function summary(): array
    {
        return [
            'total_users' => User::query()->count(),
            'beta_users' => User::query()->whereNotNull('beta_status')->count(),
            'whatsapp_verified' => User::query()->whereNotNull('whatsapp_verified_at')->count(),
            'active_paid' => User::query()
                ->whereNotNull('billing_plan_code')
                ->whereIn('billing_plan_status', ['active', 'renewed', 'cancelled'])
                ->where('billing_access_ends_at', '>', now())
                ->count(),
            'errors_7d' => WhatsAppConversationLog::query()
                ->where('created_at', '>=', now()->subDays(7))
                ->where(function ($query) {
                    $query->where('status', 'error')
                        ->orWhereNotNull('error_message');
                })
                ->count(),
        ];
    }

    /**
     * @param  Collection<int, int>  $userIds
     * @return Collection<int, WhatsAppConversationLog>
     */
    private function latestLogsFor(Collection $userIds): Collection
    {
        return WhatsAppConversationLog::query()
            ->whereIn('user_id', $userIds)
            ->latest()
            ->get()
            ->groupBy('user_id')
            ->map(fn (Collection $logs) => $logs->first());
    }

    /**
     * @param  Collection<int, int>  $userIds
     * @return Collection<int, int>
     */
    private function recentErrorCountsFor(Collection $userIds): Collection
    {
        return WhatsAppConversationLog::query()
            ->whereIn('user_id', $userIds)
            ->where('created_at', '>=', now()->subDays(7))
            ->where(function ($query) {
                $query->where('status', 'error')
                    ->orWhereNotNull('error_message')
                    ->orWhere('classification', 'default');
            })
            ->selectRaw('user_id, count(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');
    }

    /**
     * @param  Collection<int, int>  $userIds
     * @return Collection<int, AbacatePaySubscription>
     */
    private function latestSubscriptionsFor(Collection $userIds): Collection
    {
        return AbacatePaySubscription::query()
            ->whereIn('user_id', $userIds)
            ->latest()
            ->get()
            ->groupBy('user_id')
            ->map(fn (Collection $subscriptions) => $subscriptions->first());
    }
}
