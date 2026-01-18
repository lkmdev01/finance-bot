<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class EvolutionApiService
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $instanceName,
    ) {
    }

    /**
     * Envia uma mensagem de texto via WhatsApp
     */
    public function sendTextMessage(string $phoneNumber, string $message): Response
    {
        return Http::withHeaders([
            'apikey' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/message/sendText/{$this->instanceName}", [
            'number' => $phoneNumber,
            'textMessage' => [
                'text' => $message,
            ],
        ]);
    }

    /**
     * Verifica se a instância está conectada
     */
    public function checkConnection(): Response
    {
        return Http::withHeaders([
            'apikey' => $this->apiKey,
        ])->get("{$this->baseUrl}/instance/fetchInstances");
    }
}
