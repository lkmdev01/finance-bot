<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public array $preferences = [];

    public function mount(): void
    {
        $this->preferences = array_merge($this->defaults(), Auth::user()->email_preferences ?? []);
    }

    public function save(): void
    {
        $validated = $this->validate([
            'preferences.billing' => ['boolean'],
            'preferences.security' => ['boolean'],
            'preferences.login_alerts' => ['boolean'],
            'preferences.marketing' => ['boolean'],
        ]);

        Auth::user()->forceFill([
            'email_preferences' => array_merge($this->defaults(), $validated['preferences']),
        ])->save();

        $this->dispatch('email-preferences-updated');
    }

    private function defaults(): array
    {
        return [
            'billing' => true,
            'security' => true,
            'login_alerts' => true,
            'marketing' => false,
        ];
    }
}; ?>

<section class="w-full p-6 lg:p-8">
    @include('partials.settings-heading')

    <x-settings.layout heading="Preferencias de e-mail" subheading="Controle quais mensagens o InovaFinance pode enviar para voce.">
        <form wire:submit="save" class="mt-6 space-y-4">
            <label class="flex items-start gap-3 rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                <input type="checkbox" wire:model="preferences.billing" class="mt-1 rounded border-white/20 bg-slate-950 text-emerald-400">
                <span>
                    <span class="block font-bold text-white">Assinatura e pagamento</span>
                    <span class="mt-1 block text-sm text-slate-400">Confirmacoes de assinatura, renovacao, cancelamento, falhas e vencimento do plano.</span>
                </span>
            </label>

            <label class="flex items-start gap-3 rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                <input type="checkbox" wire:model="preferences.security" class="mt-1 rounded border-white/20 bg-slate-950 text-emerald-400">
                <span>
                    <span class="block font-bold text-white">Seguranca da conta</span>
                    <span class="mt-1 block text-sm text-slate-400">Avisos de senha alterada e comunicacoes importantes de protecao da conta.</span>
                </span>
            </label>

            <label class="flex items-start gap-3 rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                <input type="checkbox" wire:model="preferences.login_alerts" class="mt-1 rounded border-white/20 bg-slate-950 text-emerald-400">
                <span>
                    <span class="block font-bold text-white">Alertas de novo login</span>
                    <span class="mt-1 block text-sm text-slate-400">Receba um aviso quando houver um novo acesso na sua conta.</span>
                </span>
            </label>

            <label class="flex items-start gap-3 rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                <input type="checkbox" wire:model="preferences.marketing" class="mt-1 rounded border-white/20 bg-slate-950 text-emerald-400">
                <span>
                    <span class="block font-bold text-white">Novidades e marketing</span>
                    <span class="mt-1 block text-sm text-slate-400">Receba comunicados sobre recursos novos, ofertas e conteudos do InovaFinance.</span>
                </span>
            </label>

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit">Salvar preferencias</flux:button>

                <x-action-message class="me-3" on="email-preferences-updated">
                    Preferencias salvas.
                </x-action-message>
            </div>
        </form>
    </x-settings.layout>
</section>
