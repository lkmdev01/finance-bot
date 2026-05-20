<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    public string $name = '';
    public string $email = '';

    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($user->id),
            ],
        ]);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $this->dispatch('profile-updated', name: $user->name);
    }

    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }
}; ?>

<section class="w-full p-6 lg:p-8">
    @include('partials.settings-heading')

    <x-settings.layout heading="Perfil" subheading="Atualize seu nome e endereço de e-mail.">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" label="Nome" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" label="E-mail" type="email" required autocomplete="email" />

                @if (auth()->user() instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! auth()->user()->hasVerifiedEmail())
                    <div>
                        <flux:text class="mt-4">
                            Seu endereço de e-mail ainda não foi verificado.

                            <flux:link class="cursor-pointer text-sm" wire:click.prevent="resendVerificationNotification">
                                Clique aqui para reenviar o e-mail de verificação.
                            </flux:link>
                        </flux:text>

                        @if (session('status') === 'verification-link-sent')
                            <flux:text class="mt-2 font-medium !text-green-600 !dark:text-green-400">
                                Um novo link de verificação foi enviado para o seu e-mail.
                            </flux:text>
                        @endif
                    </div>
                @endif
            </div>

            <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-slate-300">
                <p class="font-medium text-white">Cobrança</p>
                <p class="mt-1">O CPF/CNPJ é solicitado na tela anterior ao checkout do plano, junto com a confirmação do seu número cadastrado.</p>
            </div>

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full" data-test="update-profile-button">
                        Salvar
                    </flux:button>
                </div>

                <x-action-message class="me-3" on="profile-updated">
                    Salvo.
                </x-action-message>
            </div>
        </form>

        <livewire:settings.delete-user-form />
    </x-settings.layout>
</section>
