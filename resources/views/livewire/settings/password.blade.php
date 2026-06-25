<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use App\Notifications\PasswordChangedNotification;
use Livewire\Volt\Component;

new class extends Component {
    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => ['required', 'string', 'current_password'],
                'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        $user = Auth::user();

        $user->update([
            'password' => $validated['password'],
        ]);

        if ($user->wantsEmail('security')) {
            $user->notify(new PasswordChangedNotification);
        }

        $this->reset('current_password', 'password', 'password_confirmation');

        $this->dispatch('password-updated');
    }
}; ?>

<section class="w-full p-6 lg:p-8">
    @include('partials.settings-heading')

    <x-settings.layout heading="Atualizar senha" subheading="Use uma senha forte e segura para proteger sua conta.">
        <form method="POST" wire:submit="updatePassword" class="mt-6 space-y-6">
            <flux:input
                wire:model="current_password"
                label="Senha atual"
                type="password"
                required
                autocomplete="current-password"
            />
            <flux:input
                wire:model="password"
                label="Nova senha"
                type="password"
                required
                autocomplete="new-password"
            />
            <flux:input
                wire:model="password_confirmation"
                label="Confirmar senha"
                type="password"
                required
                autocomplete="new-password"
            />

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full" data-test="update-password-button">
                        Salvar
                    </flux:button>
                </div>

                <x-action-message class="me-3" on="password-updated">
                    Salvo.
                </x-action-message>
            </div>
        </form>
    </x-settings.layout>
</section>
