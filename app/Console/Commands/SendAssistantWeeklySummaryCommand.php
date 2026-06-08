<?php

namespace App\Console\Commands;

use App\Assistant\Reports\AssistantObservabilityService;
use App\Services\AssistantOperationsSettingsService;
use App\Services\BaileysService;
use App\Services\PhoneNumberService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendAssistantWeeklySummaryCommand extends Command
{
    protected $signature = 'assistant:send-weekly-summary';

    protected $description = 'Envia o resumo semanal do SLA do assistente para o WhatsApp admin.';

    public function handle(
        AssistantOperationsSettingsService $settingsService,
        AssistantObservabilityService $observabilityService,
        PhoneNumberService $phoneNumberService,
        BaileysService $baileysService,
    ): int {
        $settings = $settingsService->current();
        $adminNumber = (string) ($settings['admin_whatsapp_number'] ?? '');

        if ($adminNumber === '') {
            $this->warn('WhatsApp admin nao configurado.');
            return self::SUCCESS;
        }

        $jid = $phoneNumberService->toWhatsAppJid($adminNumber);
        $message = $observabilityService->renderWeeklySlaAdminMessage();

        try {
            $baileysService->sendTextMessage($jid, $message);
            $this->info('Resumo semanal enviado.');
        } catch (\Throwable $exception) {
            Log::error('Falha ao enviar resumo semanal do assistente', [
                'admin_whatsapp_number' => $adminNumber,
                'error' => $exception->getMessage(),
            ]);

            $this->error('Falha ao enviar resumo semanal.');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
