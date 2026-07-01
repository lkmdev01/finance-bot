<?php

use Livewire\Volt\Component;

new class extends Component {
    //
}; ?>

<section class="w-full p-6 lg:p-8">
    @include('partials.settings-heading')

    <x-settings.layout heading="Aparencia" subheading="O InovaFinance usa o modo escuro como experiencia padrao.">
        <div
            x-data
            x-init="$flux.appearance = 'dark'; localStorage.setItem('flux.appearance', 'dark'); document.documentElement.classList.add('dark'); document.documentElement.setAttribute('data-appearance', 'dark')"
            class="rounded-3xl border border-emerald-300/20 bg-emerald-400/10 p-5 text-emerald-50"
        >
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border border-emerald-300/20 bg-emerald-300/10">
                    <flux:icon.moon class="h-6 w-6 text-emerald-200" />
                </div>

                <div>
                    <h3 class="text-lg font-black text-white">Modo escuro sempre ativo</h3>
                    <p class="mt-2 text-sm leading-6 text-emerald-50/85">
                        Para manter consistencia visual, legibilidade e identidade do produto, esta conta usa sempre o tema escuro.
                    </p>
                    <p class="mt-3 text-xs text-emerald-100/70">
                        Se o navegador tentar mudar o tema, o sistema volta automaticamente para o modo escuro.
                    </p>
                </div>
            </div>
        </div>
    </x-settings.layout>
</section>
