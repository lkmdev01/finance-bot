<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public string $password = '';
    public string $confirmation_phrase = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $this->validate(
            [
                'password' => ['required', 'string', 'current_password'],
                'confirmation_phrase' => ['required', 'string', 'in:deletar-conta'],
            ],
            [
                'password.current_password' => 'A senha informada está incorreta.',
                'confirmation_phrase.required' => 'Digite a frase de confirmação para excluir a conta.',
                'confirmation_phrase.in' => 'Digite exatamente deletar-conta para confirmar a exclusão.',
            ],
            [
                'password' => 'senha',
                'confirmation_phrase' => 'frase de confirmação',
            ],
        );

        tap(Auth::user(), $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

<section class="mt-10 space-y-6">
    <div class="relative mb-5">
        <flux:heading>Excluir conta</flux:heading>
        <flux:subheading>Exclua sua conta e todos os dados vinculados a ela.</flux:subheading>
    </div>

    <flux:modal.trigger name="confirm-user-deletion">
        <flux:button variant="danger" x-data="" x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')" data-test="delete-user-button">
            Excluir conta
        </flux:button>
    </flux:modal.trigger>

    <flux:modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
        <form method="POST" wire:submit="deleteUser" class="space-y-6">
            <div>
                <flux:heading size="lg">Tem certeza de que deseja excluir sua conta?</flux:heading>

                <flux:subheading>
                    Depois que sua conta for excluída, todos os dados e recursos vinculados a ela serão removidos permanentemente. Para confirmar, digite sua senha e a frase <span class="font-semibold text-white">deletar-conta</span>.
                </flux:subheading>
            </div>

            <flux:input wire:model="password" label="Senha" type="password" />

            <flux:input
                wire:model="confirmation_phrase"
                label="Confirmação de exclusão"
                type="text"
                placeholder="Digite deletar-conta"
            />

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">Cancelar</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" type="submit" data-test="confirm-delete-user-button">
                    Excluir conta
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
