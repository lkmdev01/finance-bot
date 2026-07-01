<?php

use App\Models\User;
use App\Models\WhatsAppActivationCode;
use App\Models\WhatsAppContact;
use App\Services\PhoneNumberService;
use App\Services\WhatsAppActivationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public string $phone_number = '';

    public ?string $activationCode = null;

    public ?string $activationWhatsAppUrl = null;

    public ?string $displayCurrentPhone = null;

    public ?string $verifiedPhone = null;

    public bool $hasPendingChange = false;

    public function mount(PhoneNumberService $phoneNumberService, WhatsAppActivationService $activationService): void
    {
        $this->refreshState($phoneNumberService, $activationService);
    }

    public function startPhoneChange(PhoneNumberService $phoneNumberService, WhatsAppActivationService $activationService): void
    {
        $this->validate([
            'phone_number' => [
                'required',
                'string',
                'max:20',
                'regex:/^[0-9+\-\s()]+$/',
                function (string $attribute, mixed $value, \Closure $fail) use ($phoneNumberService): void {
                    if (! $phoneNumberService->isValid((string) $value)) {
                        $fail('Informe um numero valido com DDD.');
                    }
                },
            ],
        ], [
            'phone_number.required' => 'Informe o novo numero que voce quer usar no WhatsApp.',
            'phone_number.regex' => 'Use um numero valido com DDD.',
        ]);

        $user = Auth::user();
        $normalizedPhone = $phoneNumberService->formatForStorage($this->phone_number);

        if (! $user instanceof User) {
            abort(403);
        }

        if ($normalizedPhone === $user->phone_number && $user->whatsapp_verified_at) {
            $this->addError('phone_number', 'Este numero ja esta validado na sua conta.');

            return;
        }

        if (User::query()->where('phone_number', $normalizedPhone)->whereKeyNot($user->id)->exists()) {
            $this->addError('phone_number', 'Este numero ja esta sendo usado por outra conta.');

            return;
        }

        $activation = $activationService->issueForUser($user, $this->activationClientKey($user));

        $this->activationCode = $activation->code;
        $this->activationWhatsAppUrl = $activationService->buildWhatsAppUrl($activation->code);
        $this->hasPendingChange = true;
        $this->verifiedPhone = null;

        $this->dispatch('whatsapp-change-started');
    }

    public function confirmPhoneChange(PhoneNumberService $phoneNumberService, WhatsAppActivationService $activationService): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(403);
        }

        try {
            $activation = $this->verifiedActivationForSettings($user);
        } catch (\RuntimeException $exception) {
            $this->addError('activation', $exception->getMessage());

            return;
        }

        $phoneNumber = (string) $activation->verified_phone_number;

        $activationService->consume($activation, $user, $phoneNumber);

        WhatsAppContact::query()->updateOrCreate(
            ['phone_number' => $phoneNumber],
            [
                'user_id' => $user->id,
                'name' => $user->name,
            ],
        );

        $this->phone_number = $this->formatDisplayPhone($phoneNumber, $phoneNumberService);
        $this->hasPendingChange = false;
        $this->activationCode = null;
        $this->activationWhatsAppUrl = null;
        $this->verifiedPhone = null;

        $this->refreshState($phoneNumberService, $activationService);
        $this->dispatch('whatsapp-phone-updated');
    }

    public function cancelPhoneChange(PhoneNumberService $phoneNumberService, WhatsAppActivationService $activationService): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(403);
        }

        WhatsAppActivationCode::query()
            ->where('user_id', $user->id)
            ->where('client_key', $this->activationClientKey($user))
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $this->refreshState($phoneNumberService, $activationService);
        $this->dispatch('whatsapp-change-cancelled');
    }

    private function refreshState(PhoneNumberService $phoneNumberService, WhatsAppActivationService $activationService): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(403);
        }

        $this->displayCurrentPhone = filled($user->phone_number)
            ? $this->formatDisplayPhone($user->phone_number, $phoneNumberService)
            : null;
        $this->phone_number = $this->displayCurrentPhone ?? '';

        $activation = WhatsAppActivationCode::query()
            ->where('user_id', $user->id)
            ->where('client_key', $this->activationClientKey($user))
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $activation || $activation->isExpired()) {
            $this->hasPendingChange = false;
            $this->activationCode = null;
            $this->activationWhatsAppUrl = null;
            $this->verifiedPhone = null;

            return;
        }

        $this->hasPendingChange = true;
        $this->activationCode = $activation->code;
        $this->activationWhatsAppUrl = $activationService->buildWhatsAppUrl($activation->code);
        $this->verifiedPhone = filled($activation->verified_phone_number)
            ? $this->formatDisplayPhone($activation->verified_phone_number, $phoneNumberService)
            : null;
    }

    private function verifiedActivationForSettings(User $user): WhatsAppActivationCode
    {
        $activation = WhatsAppActivationCode::query()
            ->where('user_id', $user->id)
            ->where('client_key', $this->activationClientKey($user))
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $activation || $activation->isExpired()) {
            throw new \RuntimeException('Seu codigo expirou. Gere um novo codigo para trocar o numero.');
        }

        if (! $activation->isVerified() || blank($activation->verified_phone_number)) {
            throw new \RuntimeException('Envie o codigo pelo novo WhatsApp antes de confirmar a troca.');
        }

        if (User::query()->where('phone_number', $activation->verified_phone_number)->whereKeyNot($user->id)->exists()) {
            throw new \RuntimeException('Este numero foi validado, mas ja pertence a outra conta.');
        }

        return $activation;
    }

    private function activationClientKey(User $user): string
    {
        return 'settings-phone-change-'.$user->id;
    }

    private function formatDisplayPhone(string $phoneNumber, PhoneNumberService $phoneNumberService): string
    {
        $clean = $phoneNumberService->clean($phoneNumber);

        if (str_starts_with($clean, '55') && strlen($clean) > 11) {
            $clean = substr($clean, 2);
        }

        return $phoneNumberService->format($clean);
    }
}; ?>

<section class="w-full p-6 lg:p-8">
    @include('partials.settings-heading')

    <x-settings.layout heading="WhatsApp" subheading="Troque ou valide o numero que conversa com o InovaFinance.">
        <div class="my-6 w-full space-y-6">
            <div class="rounded-3xl border border-emerald-300/20 bg-emerald-400/10 p-5 text-emerald-50">
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-100/80">Numero atual</p>
                <h3 class="mt-2 text-2xl font-black text-white">{{ $displayCurrentPhone ?: 'Nenhum numero validado' }}</h3>
                <p class="mt-2 text-sm leading-6 text-emerald-50/85">
                    Seus dados continuam na mesma conta. Se voce trocar de numero, o acesso via WhatsApp passa a funcionar somente no novo numero validado.
                </p>
            </div>

            @if ($errors->any())
                <div class="rounded-2xl border border-rose-400/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
                    <p class="font-bold">Nao foi possivel continuar:</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (! $hasPendingChange)
                <form wire:submit="startPhoneChange" class="space-y-4" x-data="{
                    mask(value) {
                        let x = value.replace(/\D/g, '').match(/(\d{0,2})(\d{0,5})(\d{0,4})/);
                        if (!x[2]) return x[1];
                        return '(' + x[1] + ') ' + x[2] + (x[3] ? '-' + x[3] : '');
                    }
                }">
                    <div>
                        <flux:input
                            wire:model="phone_number"
                            x-on:input="$el.value = mask($event.target.value)"
                            label="Novo numero de WhatsApp"
                            type="tel"
                            placeholder="(11) 99999-9999"
                            hint="Informe DDD e numero. O +55 sera adicionado automaticamente."
                        />
                    </div>

                    <div class="rounded-2xl border border-white/10 bg-white/[0.04] p-4 text-sm leading-6 text-slate-300">
                        <p class="font-bold text-white">Como funciona a troca</p>
                        <p class="mt-2">1. Voce informa o novo numero.</p>
                        <p>2. O sistema gera um codigo unico.</p>
                        <p>3. Voce envia esse codigo pelo novo WhatsApp.</p>
                        <p>4. Depois de validar, confirmamos a troca sem apagar seus dados.</p>
                    </div>

                    <div class="flex flex-wrap items-center gap-4">
                        <flux:button variant="primary" type="submit" wire:loading.attr="disabled">
                            <span wire:loading.remove>Gerar codigo de validacao</span>
                            <span wire:loading>Gerando...</span>
                        </flux:button>

                        <x-action-message class="me-3" on="whatsapp-phone-updated">
                            {{ __('Numero trocado com sucesso!') }}
                        </x-action-message>
                    </div>
                </form>
            @else
                <div class="space-y-5 rounded-3xl border border-cyan-300/20 bg-cyan-400/10 p-5 text-cyan-50">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-cyan-100/80">Validacao pendente</p>
                        <h3 class="mt-2 text-xl font-black text-white">Envie o codigo pelo novo WhatsApp</h3>
                        <p class="mt-2 text-sm leading-6 text-cyan-50/85">
                            Abra a conversa oficial e envie exatamente o codigo abaixo. Quando o bot responder que conectou, volte aqui e confirme.
                        </p>
                    </div>

                    <div class="rounded-2xl border border-white/10 bg-slate-950/80 p-5">
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">Codigo unico</p>
                        <p class="mt-3 break-all font-mono text-3xl font-black tracking-[0.14em] text-white">{{ $activationCode }}</p>
                    </div>

                    @if ($verifiedPhone)
                        <div class="rounded-2xl border border-emerald-300/20 bg-emerald-400/10 p-4 text-sm text-emerald-50">
                            Codigo recebido pelo numero <span class="font-bold text-white">{{ $verifiedPhone }}</span>. Agora confirme para concluir.
                        </div>
                    @endif

                    <div class="flex flex-col gap-3 sm:flex-row">
                        @if ($activationWhatsAppUrl)
                            <a href="{{ $activationWhatsAppUrl }}" target="_blank" rel="noopener" class="inline-flex items-center justify-center rounded-2xl bg-emerald-400 px-5 py-3 text-sm font-black text-slate-950 transition hover:bg-emerald-300">
                                Enviar codigo no WhatsApp
                            </a>
                        @endif

                        <flux:button variant="primary" wire:click="confirmPhoneChange" wire:loading.attr="disabled">
                            Ja enviei, confirmar troca
                        </flux:button>

                        <flux:button variant="ghost" wire:click="cancelPhoneChange" wire:loading.attr="disabled">
                            Cancelar troca
                        </flux:button>
                    </div>

                    <x-action-message on="whatsapp-change-started">
                        {{ __('Codigo gerado. Envie pelo novo WhatsApp.') }}
                    </x-action-message>

                    <x-action-message on="whatsapp-change-cancelled">
                        {{ __('Troca cancelada.') }}
                    </x-action-message>
                </div>
            @endif

            <flux:separator />

            <div class="space-y-4">
                <flux:heading size="sm">O que voce pode fazer pelo WhatsApp</flux:heading>
                <div class="space-y-2 text-sm text-slate-400">
                    <p>- Registrar transacoes: "Gastei R$ 50 no supermercado"</p>
                    <p>- Consultar saldo: "Quanto tenho disponivel?"</p>
                    <p>- Ver gastos: "Quanto gastei esse mes?"</p>
                    <p>- Salvar notas, lembretes e arquivos no Drive</p>
                    <p>- Pedir ajuda: "O que voce pode fazer?"</p>
                </div>
            </div>
        </div>
    </x-settings.layout>
</section>
