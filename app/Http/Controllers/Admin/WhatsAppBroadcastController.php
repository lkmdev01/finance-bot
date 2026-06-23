<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppBroadcast;
use App\Models\WhatsAppContact;
use App\Services\PhoneNumberService;
use App\Services\WhatsApp\WhatsAppBroadcastService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WhatsAppBroadcastController extends Controller
{
    public function index(Request $request, WhatsAppBroadcastService $broadcastService): View
    {
        $audience = (string) $request->query('audience', 'verified');
        $selectedContactIds = collect((array) $request->query('contacts', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->all();

        return view('pages.admin.whatsapp-broadcasts.index', [
            'contacts' => WhatsAppContact::query()
                ->with('user')
                ->latest('updated_at')
                ->limit(250)
                ->get(),
            'recentBroadcasts' => WhatsAppBroadcast::query()
                ->with(['admin', 'user'])
                ->latest()
                ->limit(25)
                ->get(),
            'stats' => [
                'contacts' => WhatsAppContact::count(),
                'verified' => WhatsAppContact::whereHas('user', fn ($query) => $query->whereNotNull('whatsapp_verified_at'))->count(),
                'active_30' => WhatsAppContact::where('updated_at', '>=', now()->subDays(30))->count(),
                'sent_today' => WhatsAppBroadcast::whereDate('created_at', today())->where('status', 'sent')->count(),
            ],
            'previewRecipients' => $broadcastService->recipientsFor($audience, $selectedContactIds)->take(8),
            'previewAudience' => $audience,
        ]);
    }

    public function store(Request $request, WhatsAppBroadcastService $broadcastService, PhoneNumberService $phoneNumberService): RedirectResponse
    {
        $validated = $request->validate([
            'audience' => ['required', 'string', 'in:verified,active_30,all,selected,manual'],
            'message' => ['required', 'string', 'min:3', 'max:1200'],
            'contacts' => ['array'],
            'contacts.*' => ['integer', 'exists:whats_app_contacts,id'],
            'manual_phone' => ['nullable', 'string', 'max:32'],
            'confirm_compliance' => ['accepted'],
        ], [
            'confirm_compliance.accepted' => 'Confirme que os destinatarios autorizaram receber comunicados.',
        ]);

        if ($validated['audience'] === 'manual' && ! $phoneNumberService->isValid((string) $request->input('manual_phone'))) {
            return back()
                ->withInput()
                ->withErrors(['manual_phone' => 'Informe um numero de WhatsApp valido.']);
        }

        if ($validated['audience'] === 'selected' && empty($validated['contacts'] ?? [])) {
            return back()
                ->withInput()
                ->withErrors(['contacts' => 'Selecione pelo menos um contato.']);
        }

        $result = $broadcastService->send(
            $request->user(),
            $validated['message'],
            $validated['audience'],
            $validated['contacts'] ?? [],
            $validated['manual_phone'] ?? null,
        );

        return redirect()
            ->route('admin.whatsapp-broadcasts.index')
            ->with('message', "Disparo concluido: {$result['sent']} enviado(s), {$result['failed']} falha(s), {$result['total']} destinatario(s).");
    }
}
