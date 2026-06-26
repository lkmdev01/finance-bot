<div class="flex items-start max-md:flex-col">
    <div class="me-10 w-full pb-4 md:w-[220px]">
        <flux:navlist>
            <flux:navlist.item :href="route('profile.edit')" wire:navigate>Perfil</flux:navlist.item>
            <flux:navlist.item :href="route('user-password.edit')" wire:navigate>Senha</flux:navlist.item>
            @if (Laravel\Fortify\Features::canManageTwoFactorAuthentication())
                <flux:navlist.item :href="route('two-factor.show')" wire:navigate>Autenticacao em dois fatores</flux:navlist.item>
            @endif
            <flux:navlist.item :href="route('appearance.edit')" wire:navigate>Aparencia</flux:navlist.item>
            <flux:navlist.item :href="route('whatsapp.settings')" wire:navigate>WhatsApp</flux:navlist.item>
            <flux:navlist.item :href="route('email-preferences.edit')" wire:navigate>E-mails</flux:navlist.item>
            <flux:navlist.item :href="route('assistant.operations.settings')" wire:navigate>Operacao do assistente</flux:navlist.item>
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="flex-1 self-stretch max-md:pt-6">
        <flux:heading>{{ $heading ?? '' }}</flux:heading>
        <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

        <div class="mt-5 w-full max-w-4xl">
            {{ $slot }}
        </div>
    </div>
</div>
