<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('webhook recebe mensagem do whatsapp e enfileira job', function () {
    Queue::fake();

    $user = User::factory()->create();

    $webhookData = [
        'event' => 'messages.upsert',
        'data' => [
            'key' => [
                'remoteJid' => '5511999999999@s.whatsapp.net',
                'fromMe' => false,
            ],
            'message' => [
                'messageType' => 'conversation',
                'conversation' => 'Gastei R$ 50 no supermercado',
            ],
        ],
    ];

    $response = $this->postJson(route('webhook.whatsapp'), $webhookData);

    $response->assertSuccessful();
    $response->assertJson(['status' => 'queued']);

    Queue::assertPushed(ProcessWhatsAppMessage::class, function ($job) use ($user) {
        return $job->phoneNumber === '5511999999999'
            && $job->message === 'Gastei R$ 50 no supermercado'
            && $job->userId === $user->id;
    });
});

test('webhook ignora mensagens próprias', function () {
    Queue::fake();

    $webhookData = [
        'event' => 'messages.upsert',
        'data' => [
            'key' => [
                'remoteJid' => '5511999999999@s.whatsapp.net',
                'fromMe' => true, // Mensagem enviada por nós
            ],
            'message' => [
                'messageType' => 'conversation',
                'conversation' => 'Mensagem enviada',
            ],
        ],
    ];

    $response = $this->postJson(route('webhook.whatsapp'), $webhookData);

    $response->assertSuccessful();
    $response->assertJson(['status' => 'own_message']);

    Queue::assertNothingPushed();
});

test('webhook ignora eventos que não são mensagens', function () {
    Queue::fake();

    $webhookData = [
        'event' => 'connection.update',
        'data' => [],
    ];

    $response = $this->postJson(route('webhook.whatsapp'), $webhookData);

    $response->assertSuccessful();
    $response->assertJson(['status' => 'ignored']);

    Queue::assertNothingPushed();
});
