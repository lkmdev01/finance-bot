<?php

namespace App\Services;

use App\Models\User;
use App\Models\WhatsAppActivationCode;
use Illuminate\Support\Str;

class WhatsAppActivationService
{
    public function issueForUser(User $user, string $clientKey): WhatsAppActivationCode
    {
        $activation = $this->issueForClient($clientKey);

        if ($activation->user_id !== $user->id) {
            $activation->forceFill([
                'user_id' => $user->id,
            ])->save();
        }

        return $activation->fresh();
    }

    public function issueForClient(string $clientKey): WhatsAppActivationCode
    {
        $existing = WhatsAppActivationCode::query()
            ->where('client_key', $clientKey)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if ($existing && ! $existing->isExpired()) {
            return $existing;
        }

        if ($existing) {
            $existing->update([
                'code' => $this->generateCode(),
                'verified_phone_number' => null,
                'verified_at' => null,
                'expires_at' => now()->addMinutes($this->expiresInMinutes()),
            ]);

            return $existing->fresh();
        }

        return WhatsAppActivationCode::query()->create([
            'client_key' => $clientKey,
            'code' => $this->generateCode(),
            'expires_at' => now()->addMinutes($this->expiresInMinutes()),
        ]);
    }

    public function verifyCodeFromIncomingMessage(string $rawMessage, string $phoneNumber): ?WhatsAppActivationCode
    {
        $code = $this->extractCode($rawMessage);

        if (! $code) {
            return null;
        }

        $activation = WhatsAppActivationCode::query()
            ->where('code', $code)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $activation || $activation->isExpired()) {
            return null;
        }

        $activation->forceFill([
            'verified_phone_number' => $phoneNumber,
            'verified_at' => now(),
        ])->save();

        if ($activation->user_id) {
            User::query()
                ->whereKey($activation->user_id)
                ->update([
                    'phone_number' => $phoneNumber,
                    'whatsapp_verified_at' => now(),
                ]);
        }

        return $activation->fresh();
    }

    public function assertVerifiedForRegistration(string $clientKey, string $code, string $phoneNumber): WhatsAppActivationCode
    {
        $activationQuery = WhatsAppActivationCode::query()
            ->where('code', $code)
            ->whereNull('consumed_at')
            ->latest('id');

        $activation = (clone $activationQuery)
            ->where('client_key', $clientKey)
            ->first();

        if (! $activation) {
            $activation = $activationQuery->first();
        }

        if (! $activation || $activation->isExpired()) {
            throw new \RuntimeException('Seu código de ativação expirou. Recarregue a página para gerar um novo.');
        }

        if (! $activation->isVerified()) {
            throw new \RuntimeException('Envie o código no WhatsApp antes de concluir o cadastro.');
        }

        if ($activation->verified_phone_number !== $phoneNumber) {
            throw new \RuntimeException('O código foi validado por outro número. Use o mesmo WhatsApp informado no cadastro.');
        }

        return $activation;
    }

    public function assertVerifiedForUser(User $user, string $clientKey): WhatsAppActivationCode
    {
        $activationQuery = WhatsAppActivationCode::query()
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->latest('id');

        $activation = (clone $activationQuery)
            ->where('client_key', $clientKey)
            ->first();

        if (! $activation) {
            $activation = $activationQuery->first();
        }

        if (! $activation || $activation->isExpired()) {
            throw new \RuntimeException('Seu código de ativação expirou. Gere um novo para continuar.');
        }

        if (! $activation->isVerified()) {
            throw new \RuntimeException('Envie o código no WhatsApp antes de continuar.');
        }

        if (blank($user->phone_number) || $activation->verified_phone_number !== $user->phone_number) {
            throw new \RuntimeException('O código foi validado por outro número. Use o mesmo WhatsApp informado nesta etapa.');
        }

        return $activation;
    }

    public function consume(WhatsAppActivationCode $activation, User $user, string $phoneNumber): void
    {
        $activation->forceFill([
            'consumed_at' => now(),
            'user_id' => $user->id,
            'verified_phone_number' => $phoneNumber,
            'verified_at' => $activation->verified_at ?? now(),
        ])->save();

        $user->forceFill([
            'phone_number' => $phoneNumber,
            'whatsapp_verified_at' => $activation->verified_at ?? now(),
            'onboarding_tutorial_seen_at' => now(),
        ])->save();
    }

    public function buildWhatsAppUrl(string $code): string
    {
        $contactNumber = app(PhoneNumberService::class)->clean((string) config('whatsapp.tutorial.contact_number'));
        $message = rawurlencode($code);

        return "https://wa.me/{$contactNumber}?text={$message}";
    }

    public function buildSupportWhatsAppUrl(?string $phoneNumber = null): string
    {
        $contactNumber = app(PhoneNumberService::class)->clean((string) config('whatsapp.tutorial.contact_number'));
        $message = 'Oi! Preciso ativar meu WhatsApp no InovaFinance.';

        if (filled($phoneNumber)) {
            $message .= ' Meu numero e '.$phoneNumber.'.';
        }

        return 'https://wa.me/'.$contactNumber.'?text='.rawurlencode($message);
    }

    public function activationSuccessMessage(): string
    {
        return (string) config('whatsapp.activation.success_message', '✅ WhatsApp conectado com sucesso! Seu número foi atualizado.');
    }

    public function extractCode(string $message): ?string
    {
        if (! preg_match('/\bWAUTH-\d{7}\b/i', $message, $matches)) {
            return null;
        }

        return Str::upper($matches[0]);
    }

    private function generateCode(): string
    {
        do {
            $code = 'WAUTH-'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
        } while (WhatsAppActivationCode::query()->where('code', $code)->whereNull('consumed_at')->exists());

        return $code;
    }

    private function expiresInMinutes(): int
    {
        return (int) config('whatsapp.activation.expires_minutes', 30);
    }
}
