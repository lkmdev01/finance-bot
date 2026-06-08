<?php

use App\Services\AssistantOperationsSettingsService;
use App\Services\PhoneNumberService;
use Livewire\Volt\Component;

new class extends Component
{
    public int $weekly_goal_review_runs = 1;
    public int $weekly_goal_item_approvals = 10;
    public int $weekly_goal_sync_runs = 1;
    public string $admin_whatsapp_number = '';

    public function mount(AssistantOperationsSettingsService $settingsService): void
    {
        $settings = $settingsService->current();
        $this->weekly_goal_review_runs = (int) $settings['weekly_goal_review_runs'];
        $this->weekly_goal_item_approvals = (int) $settings['weekly_goal_item_approvals'];
        $this->weekly_goal_sync_runs = (int) $settings['weekly_goal_sync_runs'];
        $this->admin_whatsapp_number = (string) $settings['admin_whatsapp_number'];
    }

    public function save(AssistantOperationsSettingsService $settingsService, PhoneNumberService $phoneNumberService): void
    {
        $normalizedAdmin = $phoneNumberService->formatForStorage($this->admin_whatsapp_number);

        $this->validate([
            'weekly_goal_review_runs' => ['required', 'integer', 'min:0', 'max:50'],
            'weekly_goal_item_approvals' => ['required', 'integer', 'min:0', 'max:500'],
            'weekly_goal_sync_runs' => ['required', 'integer', 'min:0', 'max:50'],
            'admin_whatsapp_number' => ['nullable', 'string', 'max:32'],
        ]);

        $settingsService->update([
            'weekly_goal_review_runs' => $this->weekly_goal_review_runs,
            'weekly_goal_item_approvals' => $this->weekly_goal_item_approvals,
            'weekly_goal_sync_runs' => $this->weekly_goal_sync_runs,
            'admin_whatsapp_number' => $normalizedAdmin,
        ]);

        $this->admin_whatsapp_number = $normalizedAdmin;
        $this->dispatch('assistant-operations-saved');
    }
}; ?>

<section class="w-full p-6 lg:p-8">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Operação do Assistente')" :subheading="__('Edite metas semanais e o WhatsApp que recebe o resumo operacional.')">
        <div class="my-6 w-full space-y-6">
            <form wire:submit="save" class="space-y-6">
                <div class="grid gap-4 md:grid-cols-3">
                    <flux:input wire:model="weekly_goal_review_runs" type="number" min="0" label="Meta de revisões" />
                    <flux:input wire:model="weekly_goal_sync_runs" type="number" min="0" label="Meta de syncs" />
                    <flux:input wire:model="weekly_goal_item_approvals" type="number" min="0" label="Meta de aprovações" />
                </div>

                <flux:input
                    wire:model="admin_whatsapp_number"
                    type="tel"
                    label="WhatsApp admin"
                    placeholder="5511999999999"
                    hint="Número que recebe o resumo semanal do SLA."
                />

                <div class="flex items-center gap-4">
                    <flux:button variant="primary" type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove>Salvar metas</span>
                        <span wire:loading>Salvando...</span>
                    </flux:button>

                    <x-action-message class="me-3" on="assistant-operations-saved">
                        {{ __('Configurações salvas com sucesso!') }}
                    </x-action-message>
                </div>
            </form>
        </div>
    </x-settings.layout>
</section>
