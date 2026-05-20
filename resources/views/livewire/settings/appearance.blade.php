<?php

use Livewire\Volt\Component;

new class extends Component {
    //
}; ?>

<section class="w-full p-6 lg:p-8">
    @include('partials.settings-heading')

    <x-settings.layout heading="Aparência" subheading="Atualize as preferências visuais da sua conta.">
        <flux:radio.group x-data variant="segmented" x-model="$flux.appearance">
            <flux:radio value="light" icon="sun">Claro</flux:radio>
            <flux:radio value="dark" icon="moon">Escuro</flux:radio>
            <flux:radio value="system" icon="computer-desktop">Sistema</flux:radio>
        </flux:radio.group>
    </x-settings.layout>
</section>
