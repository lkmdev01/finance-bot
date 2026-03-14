<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('webhook recebe mensagem do whatsapp e enfileira job', function () {
    Queue::fake();

    config(['whatsapp.baileys.webhook_secret' => 'test-secret']);

    $user = User::factory()->create([
        'phone_number' => '5511999999999',
    ]);

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
        'secret' => 'test-secret',
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

test('webhook ignora mensagens do bot', function () {
    Queue::fake();

    config(['whatsapp.baileys.webhook_secret' => 'test-secret']);

    $webhookData = [
        'event' => 'messages.upsert',
        'data' => [
            'key' => [
                'remoteJid' => '5511999999999@s.whatsapp.net',
                'fromMe' => true,
            ],
            'message' => [
                'messageType' => 'conversation',
                'conversation' => 'Mensagem enviada',
            ],
        ],
        'secret' => 'test-secret',
    ];

    $response = $this->postJson(route('webhook.whatsapp'), $webhookData);

    $response->assertSuccessful();
    $response->assertJson(['status' => 'bot_message_ignored']);

    Queue::assertNothingPushed();
});

test('webhook ignora eventos que nao sao mensagens', function () {
    Queue::fake();

    config(['whatsapp.baileys.webhook_secret' => 'test-secret']);

    $webhookData = [
        'event' => 'connection.update',
        'data' => [],
        'secret' => 'test-secret',
    ];

    $response = $this->postJson(route('webhook.whatsapp'), $webhookData);

    $response->assertSuccessful();
    $response->assertJson(['status' => 'ignored']);

    Queue::assertNothingPushed();
});
