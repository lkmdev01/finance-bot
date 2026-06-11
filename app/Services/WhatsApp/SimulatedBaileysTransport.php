<?php

namespace App\Services\WhatsApp;

use App\Services\BaileysService;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;

class SimulatedBaileysTransport extends BaileysService
{
    /**
     * @var array<int, array{phone:string,message:string}>
     */
    private array $messages = [];

    public function __construct()
    {
        parent::__construct('', '');
    }

    public function sendTextMessage(string $phoneNumber, string $message): Response
    {
        $this->messages[] = [
            'phone' => $phoneNumber,
            'message' => $message,
        ];

        return new Response(new Psr7Response(200, [], json_encode([
            'success' => true,
            'simulated' => true,
        ])));
    }

    /**
     * @return array<int, array{phone:string,message:string}>
     */
    public function allMessages(): array
    {
        return $this->messages;
    }

    /**
     * @return array{phone:string,message:string}|null
     */
    public function lastMessage(): ?array
    {
        return $this->messages === [] ? null : $this->messages[array_key_last($this->messages)];
    }
}
