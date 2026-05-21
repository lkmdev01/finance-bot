<?php

namespace App\Console\Commands;

use App\Models\Reminder;
use App\Models\WhatsAppContact;
use App\Services\BaileysService;
use App\Services\PhoneNumberService;
use App\Services\WhatsApp\ConversationStateService;
use App\Services\WhatsApp\ConversationTelemetryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendDueReminders extends Command
{
    protected $signature = 'reminders:send-due';

    protected $description = 'Envia lembretes agendados via WhatsApp';

    public function handle(
        BaileysService $baileysService,
        PhoneNumberService $phoneNumberService,
        ConversationStateService $stateService,
        ConversationTelemetryService $telemetryService,
    ): int {
        $sent = 0;

        Reminder::query()
            ->where('is_active', true)
            ->whereNotNull('next_trigger_at')
            ->orderBy('next_trigger_at')
            ->get()
            ->each(function (Reminder $reminder) use (&$sent, $baileysService, $phoneNumberService, $stateService, $telemetryService) {
                if (! $reminder->shouldDispatch()) {
                    return;
                }

                $user = $reminder->user;
                if ($user === null || blank($user->phone_number)) {
                    return;
                }

                $reply = $reminder->message;
                $jid = $phoneNumberService->toWhatsAppJid($user->phone_number);
                $contact = WhatsAppContact::query()
                    ->where('user_id', $user->id)
                    ->where('phone_number', $user->phone_number)
                    ->first();

                try {
                    $baileysService->sendTextMessage($jid, $reply);
                    $reminder->advanceAfterDispatch();
                    $sent++;

                    if ($contact !== null) {
                        $stateService->recordProactiveMessage($contact, $reminder->title, $reply, 'reminder:'.$reminder->id);
                    }

                    $telemetryService->record($user, $contact, $reminder->title, [
                        'classification' => 'scheduled_reminder',
                        'action' => 'send_reminder',
                        'handler' => self::class,
                        'used_ai' => false,
                        'status' => 'scheduled',
                        'reply' => $reply,
                        'metadata' => [
                            'reminder_id' => $reminder->id,
                            'frequency' => $reminder->frequency,
                        ],
                    ]);
                } catch (\Throwable $exception) {
                    Log::error('Falha ao enviar lembrete via WhatsApp', [
                        'reminder_id' => $reminder->id,
                        'user_id' => $user->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });

        $this->info("Lembretes enviados: {$sent}");

        return self::SUCCESS;
    }
}
