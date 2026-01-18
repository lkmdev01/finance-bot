<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class BaileysService
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $webhookSecret,
    ) {
    }

    /**
     * Envia uma mensagem de texto via WhatsApp
     * 
     * @param string $phoneNumber Pode ser um número (ex: 5513991290256) ou JID completo (ex: 50749417476309@lid)
     * @param string $message Mensagem a ser enviada
     */
    public function sendTextMessage(string $phoneNumber, string $message): Response
    {
        // Se já for um JID completo (contém @), usa diretamente
        // Caso contrário, remove caracteres não numéricos e adiciona @s.whatsapp.net
        if (str_contains($phoneNumber, '@')) {
            // Já é um JID completo, usa como está
            $jid = $phoneNumber;
        } else {
            // Remove caracteres não numéricos do número
            $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
            $jid = $phoneNumber.'@s.whatsapp.net';
        }

        $response = Http::timeout(30)->post("{$this->baseUrl}/send-message", [
            'phone' => $jid,
            'message' => $message,
            'secret' => $this->webhookSecret,
        ]);

        // Log se houver erro
        if ($response->failed()) {
            \Illuminate\Support\Facades\Log::error('Erro ao enviar mensagem via Baileys', [
                'status' => $response->status(),
                'response' => $response->body(),
                'jid' => $jid,
            ]);
        }

        return $response;
    }

    /**
     * Verifica se o WhatsApp está conectado
     */
    public function checkConnection(): Response
    {
        return Http::timeout(10)->get("{$this->baseUrl}/status");
    }
}
