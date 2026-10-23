<?php

namespace App\Services\Meetings;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class VexaApiClient
{
    private function request(): PendingRequest
    {
        $baseUrl = rtrim((string) config('vexa.base_url'), '/');
        $apiKey = (string) config('vexa.api_key');
        if ($baseUrl === '' || $apiKey === '') {
            throw new RuntimeException('Integração Vexa não configurada.');
        }

        return Http::acceptJson()->asJson()->withHeaders(['X-API-Key' => $apiKey])->timeout(20)->baseUrl($baseUrl);
    }

    public function isAvailable(): bool
    {
        try {
            $response = $this->request()->get('/auth/me');
            $scopes = $response->json('scopes', []);

            return $response->successful()
                && is_array($scopes)
                && in_array('bot', $scopes, true)
                && in_array('tx', $scopes, true);
        } catch (\Throwable) {
            return false;
        }
    }

    public function sendBot(string $meetingUrl): array
    {
        $response = $this->request()->post('/bots', [
            'meeting_url' => $meetingUrl,
            'bot_name' => (string) config('vexa.bot_name', 'InovaFinance'),
            'recording_enabled' => true,
            'transcribe_enabled' => false,
            ... (filled(config('vexa.language')) ? ['language' => config('vexa.language')] : []),
        ]);

        // A retry after a network timeout may arrive after Vexa accepted the first request.
        // Vexa reports an active bot for the same link as 409; converge on status polling.
        if ($response->status() === 409) {
            return ['status' => 'requested', 'already_active' => true];
        }

        if (! $response->successful()) {
            throw new RuntimeException('Vexa recusou a solicitação (HTTP '.$response->status().').');
        }

        return $response->json() ?? [];
    }

    public function transcript(string $platform, string $nativeMeetingId): array
    {
        $response = $this->request()->get('/transcripts/'.rawurlencode($platform).'/'.rawurlencode($nativeMeetingId));
        if (! $response->successful()) {
            throw new RuntimeException('Não foi possível consultar a transcrição (HTTP '.$response->status().').');
        }

        return $response->json() ?? [];
    }

    public function meeting(string $vexaMeetingId): array
    {
        $response = $this->request()->get('/meetings/'.rawurlencode($vexaMeetingId));
        if (! $response->successful()) {
            throw new \RuntimeException('Não foi possível consultar o estado da reunião (HTTP '.$response->status().').');
        }
        return $response->json() ?? [];
    }

    public function configureWebhook(string $url, string $secret): void
    {
        $response = $this->request()->put('/user/webhook', [
            'webhook_url' => $url,
            'webhook_secret' => $secret,
            'webhook_events' => [
                'meeting.status_change' => true,
                'meeting.started' => true,
                'meeting.completed' => true,
                'bot.failed' => true,
            ],
        ]);
        if (! $response->successful()) {
            throw new \RuntimeException('Vexa não aceitou a configuração de webhook (HTTP '.$response->status().').');
        }
    }
}
