<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PhoneNumberService;
use App\Services\WhatsAppActivationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WhatsAppActivationController extends Controller
{
    public function __construct(
        private readonly PhoneNumberService $phoneNumberService,
        private readonly WhatsAppActivationService $activationService,
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
                function (string $attribute, mixed $value, \Closure $fail) use ($user) {
                    if (! $this->phoneNumberService->isValid((string) $value)) {
                        $fail('Informe um número válido com DDD.');
                        return;
                    }

                    $normalizedPhone = $this->phoneNumberService->formatForStorage((string) $value);
                    $exists = User::query()
                        ->where('phone_number', $normalizedPhone)
                        ->where('id', '!=', $user->id)
                        ->exists();

                    if ($exists) {
                        $fail('Esse número já está sendo usado por outra conta.');
                    }
                },
            ],
        ], [
            'phone_number.required' => 'Informe o número que você vai usar no WhatsApp.',
            'phone_number.regex' => 'Use um número válido com DDD.',
        ]);

        $user->forceFill([
            'phone_number' => $this->phoneNumberService->formatForStorage($validated['phone_number']),
            'whatsapp_verified_at' => null,
        ])->save();

        $this->activationService->issueForUser($user, $request->session()->getId());

        return redirect()
            ->route('whatsapp.activation.show')
            ->with('status', 'Número salvo. Agora envie o código para concluir a ativação.');
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
            ->with('status', 'WhatsApp conectado com sucesso. Sua conta está pronta para uso.');
    }
}
