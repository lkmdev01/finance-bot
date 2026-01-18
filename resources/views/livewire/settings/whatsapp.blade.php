<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Volt\Component;

new class extends Component
{
    public string $phone_number = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->phone_number = Auth::user()->phone_number ?? '';
    }

    /**
     * Update the phone number for the currently authenticated user.
     */
    public function updatePhoneNumber(): void
    {
        $validated = $this->validate([
            'phone_number' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]+$/'],
        ]);

        // Remove caracteres não numéricos, exceto +
        $phoneNumber = preg_replace('/[^0-9+]/', '', $validated['phone_number'] ?? '');

        // Se o número estiver vazio após limpeza, define como null
        if (empty($phoneNumber)) {
            $phoneNumber = null;
        }

        // Atualiza o número do usuário
        $user = Auth::user();
        $user->phone_number = $phoneNumber;
        $user->save();

        // Atualiza o valor local para refletir a mudança
        $this->phone_number = $phoneNumber ?? '';

        // Log para debug
        Log::info('Número de telefone atualizado via interface', [
            'user_id' => $user->id,
            'phone_number' => $phoneNumber,
            'database' => DB::connection()->getDatabaseName(),
        ]);

        $this->dispatch('phone-number-updated');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('WhatsApp')" :subheading="__('Vincule seu número de telefone para usar o WhatsApp')">
        <div class="my-6 w-full space-y-6">
            <!-- Configuração do Número de Telefone -->
            <div class="space-y-4">
                <flux:heading size="sm">Vincular Número de Telefone</flux:heading>
                <flux:text class="text-sm text-gray-600 dark:text-gray-400">
                    Vincule seu número de telefone para que o sistema identifique suas mensagens do WhatsApp.
                </flux:text>

                <form wire:submit="updatePhoneNumber" class="space-y-4">
                    <flux:input
                        wire:model="phone_number"
                        label="Número de Telefone"
                        type="tel"
                        placeholder="+5511999999999"
                        hint="Digite seu número com código do país (ex: +5511999999999)"
                    />

                    <div class="flex items-center gap-4">
                        <flux:button variant="primary" type="submit" wire:loading.attr="disabled">
                            <span wire:loading.remove>Salvar</span>
                            <span wire:loading>Salvando...</span>
                        </flux:button>

                        <x-action-message class="me-3" on="phone-number-updated">
                            {{ __('Número salvo com sucesso!') }}
                        </x-action-message>
                    </div>

                    @if($phone_number)
                        <flux:text class="text-sm text-green-600 dark:text-green-400">
                            Número atual: {{ $phone_number }}
                        </flux:text>
                    @endif
                </form>
            </div>

            <flux:separator />

            <!-- Informações -->
            <div class="space-y-4">
                <flux:heading size="sm">Como Funciona</flux:heading>
                <div class="space-y-2 text-sm text-gray-600 dark:text-gray-400">
                    <p>• Envie mensagens para o número conectado no WhatsApp</p>
                    <p>• Registre transações: "Gastei R$ 50 no supermercado"</p>
                    <p>• Consulte saldo: "Quanto tenho disponível?"</p>
                    <p>• Veja gastos: "Quanto gastei esse mês?"</p>
                    <p>• Peça ajuda: "O que você pode fazer?"</p>
                </div>
            </div>
        </div>
    </x-settings.layout>
</section>
