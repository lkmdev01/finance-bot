<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use App\Models\WhatsAppBroadcast;
use App\Models\WhatsAppContact;
use App\Services\BaileysService;
use App\Services\PhoneNumberService;
use App\Services\WhatsAppFormatter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsAppBroadcastService
{
    public function __construct(
        private readonly BaileysService $baileysService,
        private readonly PhoneNumberService $phoneNumberService,
    ) {}

    public function recipientsFor(string $audience, array $contactIds = [], ?string $manualPhone = null): Collection
    {
        if ($audience === 'manual') {
            $phone = $this->phoneNumberService->formatForStorage((string) $manualPhone);

            return $phone === '' ? collect() : collect([[
                'user_id' => null,
                'contact_id' => null,
                'name' => 'Numero manual',
                'phone' => $phone,
            ]]);
        }

        $query = WhatsAppContact::query()
            ->with('user')
            ->whereNotNull('phone_number')
            ->where('phone_number', '!=', '');

        match ($audience) {
            'verified' => $query->whereHas('user', fn ($userQuery) => $userQuery->whereNotNull('whatsapp_verified_at')),
            'active_30' => $query->where('updated_at', '>=', now()->subDays(30)),
            'selected' => $query->whereIn('id', $contactIds),
            default => null,
        };

        return $query
            ->latest('updated_at')
            ->get()
            ->map(fn (WhatsAppContact $contact) => [
                'user_id' => $contact->user_id,
                'contact_id' => $contact->id,
                'name' => $contact->user?->name ?: $contact->name ?: 'Contato WhatsApp',
                'phone' => $this->phoneNumberService->formatForStorage($contact->phone_number),
            ])
            ->filter(fn (array $recipient) => $recipient['phone'] !== '')
            ->unique('phone')
            ->values();
    }

    public function send(User $admin, string $message, string $audience, array $contactIds = [], ?string $manualPhone = null): array
    {
        $message = WhatsAppFormatter::format(trim($message));
        $recipients = $this->recipientsFor($audience, $contactIds, $manualPhone);
        $sent = 0;
        $failed = 0;

        foreach ($recipients as $recipient) {
            $broadcast = WhatsAppBroadcast::create([
                'admin_user_id' => $admin->id,
                'user_id' => $recipient['user_id'],
                'whats_app_contact_id' => $recipient['contact_id'],
                'recipient_name' => $recipient['name'],
                'phone_number' => $recipient['phone'],
                'audience' => $audience,
                'message' => $message,
                'status' => 'pending',
            ]);

            try {
                $response = $this->baileysService->sendTextMessage(
                    $this->phoneNumberService->toWhatsAppJid($recipient['phone']),
                    $message,
                );

                $ok = $response->successful();
                $broadcast->update([
                    'status' => $ok ? 'sent' : 'failed',
                    'provider_status' => $response->status(),
                    'provider_response' => $response->json() ?: ['body' => $response->body()],
                    'error_message' => $ok ? null : $response->body(),
                    'sent_at' => $ok ? now() : null,
                ]);

                $ok ? $sent++ : $failed++;
            } catch (Throwable $exception) {
                $failed++;

                $broadcast->update([
                    'status' => 'failed',
                    'error_message' => $exception->getMessage(),
                ]);

                Log::error('Falha ao enviar disparo WhatsApp admin', [
                    'broadcast_id' => $broadcast->id,
                    'phone' => $recipient['phone'],
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'total' => $recipients->count(),
            'sent' => $sent,
            'failed' => $failed,
        ];
    }
}
