<?php

use App\Services\AssistantToneService;
use Livewire\Volt\Component;

new class extends Component
{
    public string $assistant_tone = 'friendly';

    public function mount(): void
    {
        $this->assistant_tone = auth()->user()->assistant_tone ?: 'friendly';
    }

    public function save(): void
    {
        $this->validate([
            'assistant_tone' => ['required', 'string', 'in:'.implode(',', array_keys(AssistantToneService::OPTIONS))],
        ]);

        auth()->user()->forceFill(['assistant_tone' => $this->assistant_tone])->save();
        $this->dispatch('assistant-tone-saved');
    }
}; ?>

<section class="w-full p-6 lg:p-8">
    @include('partials.settings-heading')
    <x-settings.layout heading="Tom do assistente" subheading="Escolha como o assistente conversa com você em todos os agentes.">
        <form wire:submit="save" class="my-6 max-w-xl space-y-5">
            <flux:field>
                <flux:label>Estilo de conversa</flux:label>
                <flux:select wire:model="assistant_tone">
                    @foreach (\App\Services\AssistantToneService::OPTIONS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
                <flux:description>O estilo sarcástico é afiado, rebelde e direto. A ironia mira ideias e situações; em assuntos delicados ou ações financeiras, o assistente prioriza clareza e cuidado.</flux:description>
                <flux:error name="assistant_tone" />
            </flux:field>
            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit">Salvar preferência</flux:button>
                <x-action-message on="assistant-tone-saved">Preferência salva.</x-action-message>
            </div>
        </form>
    </x-settings.layout>
</section>
