<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PhoneNumberService;
use App\Services\UserAccountReconciliationService;
use App\Services\WhatsAppActivationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class WhatsAppActivationController extends Controller
{
    public function __construct(
        private readonly PhoneNumberService $phoneNumberService,
        private readonly WhatsAppActivationService $activationService,
        private readonly UserAccountReconciliationService $accountReconciliationService,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        if ($user->whatsapp_verified_at) {
            return redirect()->route('dashboard');
        }

        $activation = null;
        $activationUrl = null;

        if (filled($user->phone_number)) {
            $activation = $this->activationService->issueForUser($user, $request->session()->getId());
            $activationUrl = $this->activationService->buildWhatsAppUrl($activation->code);
        }

        return view('auth.whatsapp-activation', [
            'user' => $user,
            'displayPhoneNumber' => filled($user->phone_number)
                ? $this->phoneNumberService->format(substr($user->phone_number, 2))
                : null,
            'activationCode' => $activation?->code,
            'activationWhatsAppUrl' => $activationUrl,
            'supportWhatsAppUrl' => $this->activationService->buildSupportWhatsAppUrl($user->phone_number),
        ]);
    }

    public function updatePhone(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        if ($user->whatsapp_verified_at) {
            return redirect()->route('dashboard');
        }

        $validated = $request->validate([
            'phone_number' => [
                'required',
                'string',
                'max:20',
                'regex:/^[0-9+\-\s()]+$/',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $this->phoneNumberService->isValid((string) $value)) {
                        $fail('Informe um numero valido com DDD.');
                    }
                },
            ],
        ], [
            'phone_number.required' => 'Informe o numero que voce vai usar no WhatsApp.',
            'phone_number.regex' => 'Use um numero valido com DDD.',
        ]);

        $normalizedPhone = $this->phoneNumberService->formatForStorage($validated['phone_number']);

        try {
            $resolvedUser = $this->accountReconciliationService->reconcileLegacyPhoneOwner($user, $normalizedPhone);
        } catch (\RuntimeException $exception) {
            return back()
                ->withInput()
                ->withErrors(['phone_number' => $exception->getMessage()]);
        }

        if ($resolvedUser->id !== $user->id) {
            Auth::login($resolvedUser, remember: true);
            $request->session()->regenerate();
            $user = $resolvedUser;
        }

        $user->forceFill([
            'phone_number' => $normalizedPhone,
            'whatsapp_verified_at' => null,
        ])->save();

        $this->activationService->issueForUser($user, $request->session()->getId());

        return redirect()
            ->route('whatsapp.activation.show')
            ->with('status', 'Numero salvo. Agora envie o codigo para concluir a ativacao.');
    }

    public function complete(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        if ($user->whatsapp_verified_at) {
            return redirect()->route('dashboard');
        }

        try {
            $activation = $this->activationService->assertVerifiedForUser($user, $request->session()->getId());
        } catch (\RuntimeException $exception) {
            return back()->withErrors([
                'activation' => $exception->getMessage(),
            ]);
        }

        $this->activationService->consume($activation, $user, $user->phone_number);

        return redirect()
            ->route('dashboard')
            ->with('status', 'WhatsApp conectado com sucesso. Sua conta esta pronta para uso.');
    }
}
