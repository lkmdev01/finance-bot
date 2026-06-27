<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\AdminBroadcastMail;
use App\Models\EmailLog;
use App\Models\User;
use App\Services\AdminEmailBroadcastService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailBroadcastController extends Controller
{
    public function index(Request $request, AdminEmailBroadcastService $broadcastService): View
    {
        $audience = (string) $request->query('audience', 'marketing_opt_in');
        $selectedUserIds = collect((array) $request->query('users', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->all();

        return view('pages.admin.email-broadcasts.index', [
            'users' => User::query()
                ->orderByDesc('updated_at')
                ->limit(250)
                ->get(['id', 'name', 'email', 'email_preferences', 'billing_plan_code', 'billing_plan_status', 'whatsapp_verified_at', 'updated_at']),
            'recentEmails' => EmailLog::query()
                ->with('user')
                ->where('notification_type', AdminBroadcastMail::class)
                ->latest()
                ->limit(20)
                ->get(),
            'stats' => [
                'users' => User::query()->whereNotNull('email')->count(),
                'marketing_opt_in' => User::query()->where('email_preferences->marketing', true)->count(),
                'paid_active' => User::query()
                    ->whereNotNull('billing_plan_code')
                    ->whereIn('billing_plan_status', ['active', 'renewed', 'cancelled'])
                    ->where('billing_access_ends_at', '>', now())
                    ->count(),
                'sent_today' => EmailLog::query()
                    ->whereDate('created_at', today())
                    ->where('notification_type', AdminBroadcastMail::class)
                    ->where('status', 'sent')
                    ->count(),
            ],
            'previewRecipients' => $broadcastService->recipientsFor($audience, $selectedUserIds)->take(8),
            'previewAudience' => $audience,
        ]);
    }

    public function store(Request $request, AdminEmailBroadcastService $broadcastService): RedirectResponse
    {
        $validated = $request->validate([
            'audience' => ['required', 'string', 'in:marketing_opt_in,verified_email,paid_active,whatsapp_verified,selected,manual'],
            'subject' => ['required', 'string', 'min:3', 'max:120'],
            'preheader' => ['nullable', 'string', 'max:180'],
            'headline' => ['required', 'string', 'min:3', 'max:140'],
            'body' => ['required', 'string', 'min:10', 'max:5000'],
            'cta_label' => ['nullable', 'string', 'max:40'],
            'cta_url' => ['nullable', 'url', 'max:255'],
            'users' => ['array'],
            'users.*' => ['integer', 'exists:users,id'],
            'manual_email' => ['nullable', 'email:rfc', 'max:255'],
            'confirm_compliance' => ['accepted'],
        ], [
            'confirm_compliance.accepted' => 'Confirme que os destinatarios autorizaram receber comunicados.',
        ]);

        if ($validated['audience'] === 'manual' && blank($validated['manual_email'] ?? null)) {
            return back()
                ->withInput()
                ->withErrors(['manual_email' => 'Informe o e-mail manual.']);
        }

        if ($validated['audience'] === 'selected' && empty($validated['users'] ?? [])) {
            return back()
                ->withInput()
                ->withErrors(['users' => 'Selecione pelo menos um usuario.']);
        }

        $result = $broadcastService->send(
            $request->user(),
            $validated,
            $validated['users'] ?? [],
        );

        return redirect()
            ->route('admin.email-broadcasts.index')
            ->with('message', "Disparo de e-mail concluido: {$result['sent']} enviado(s), {$result['failed']} falha(s), {$result['total']} destinatario(s).");
    }
}
