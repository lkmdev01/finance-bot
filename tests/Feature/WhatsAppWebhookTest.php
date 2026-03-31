<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AudioTranscriptionService;
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

test('webhook transcreve audio e enfileira job', function () {
    Queue::fake();

    config(['whatsapp.baileys.webhook_secret' => 'test-secret']);

    $user = User::factory()->create([
        'phone_number' => '5511999999999',
    ]);

    $this->mock(AudioTranscriptionService::class, function ($mock) {
        $mock->shouldReceive('transcribeBase64')
            ->once()
            ->andReturn('Gastei 30 no cafe');
    });

    $webhookData = [
        'event' => 'messages.upsert',
        'data' => [
            'key' => [
                'remoteJid' => '5511999999999@s.whatsapp.net',
                'fromMe' => false,
            ],
            'message' => [
                'messageType' => 'audioMessage',
                'audioMessage' => [
                    'mimetype' => 'audio/ogg; codecs=opus',
                    'seconds' => 5,
                ],
            ],
            'audioBase64' => base64_encode('fake-audio'),
            'audioMimeType' => 'audio/ogg; codecs=opus',
        ],
        'secret' => 'test-secret',
    ];

    $response = $this->postJson(route('webhook.whatsapp'), $webhookData);

    $response->assertSuccessful();
    $response->assertJson(['status' => 'queued']);

    Queue::assertPushed(ProcessWhatsAppMessage::class, function ($job) use ($user) {
        return $job->phoneNumber === '5511999999999'
            && $job->message === 'Gastei 30 no cafe'
            && $job->userId === $user->id;
    });
});

test('webhook retorna erro amigavel quando audio nao pode ser transcrito', function () {
    Queue::fake();

    config(['whatsapp.baileys.webhook_secret' => 'test-secret']);

    User::factory()->create([
        'phone_number' => '5511999999999',
    ]);

    $this->mock(AudioTranscriptionService::class, function ($mock) {
        $mock->shouldReceive('transcribeBase64')
            ->once()
            ->andReturn(null);
    });

    $this->mock(\App\Services\BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->andReturn(['success' => true]);
    });

    $webhookData = [
        'event' => 'messages.upsert',
        'data' => [
            'key' => [
                'remoteJid' => '5511999999999@s.whatsapp.net',
                'fromMe' => false,
            ],
            'message' => [
                'messageType' => 'audioMessage',
                'audioMessage' => [
                    'mimetype' => 'audio/ogg; codecs=opus',
                    'seconds' => 5,
                ],
            ],
            'audioBase64' => base64_encode('fake-audio'),
            'audioMimeType' => 'audio/ogg; codecs=opus',
        ],
        'secret' => 'test-secret',
    ];

    $response = $this->postJson(route('webhook.whatsapp'), $webhookData);

    $response->assertSuccessful();
    $response->assertJson(['status' => 'audio_transcription_failed']);

    Queue::assertNothingPushed();
});

test('webhook importa csv enviado como documento no whatsapp', function () {
    Queue::fake();

    config(['whatsapp.baileys.webhook_secret' => 'test-secret']);

    $user = User::factory()->create([
        'phone_number' => '5511999999999',
    ]);

    $this->mock(\App\Services\BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->andReturn(['success' => true]);
    });

    $csv = "Data,Descricao,Valor,Tipo\n";
    $csv .= "2026-03-10,Padaria,25.50,expense\n";

    $webhookData = [
        'event' => 'messages.upsert',
        'data' => [
            'key' => [
                'remoteJid' => '5511999999999@s.whatsapp.net',
                'fromMe' => false,
            ],
            'message' => [
                'messageType' => 'documentMessage',
                'documentMessage' => [
                    'mimetype' => 'text/csv',
                    'fileName' => 'extrato.csv',
                ],
            ],
            'documentBase64' => base64_encode($csv),
            'documentMimeType' => 'text/csv',
            'documentFileName' => 'extrato.csv',
        ],
        'secret' => 'test-secret',
    ];

    $response = $this->postJson(route('webhook.whatsapp'), $webhookData);

    $response->assertSuccessful();
    $response->assertJson([
        'status' => 'document_imported',
        'imported' => 1,
    ]);

    expect(Transaction::where('user_id', $user->id)->count())->toBe(1);
    Queue::assertNothingPushed();
});

test('webhook encaminha texto extraido de pdf para fila', function () {
    Queue::fake();

    config(['whatsapp.baileys.webhook_secret' => 'test-secret']);

    $user = User::factory()->create([
        'phone_number' => '5511999999999',
    ]);

    $pdfLike = "%PDF-1.4\n(Compra mercado 89,90 no cartao)\n%%EOF";

    $webhookData = [
        'event' => 'messages.upsert',
        'data' => [
            'key' => [
                'remoteJid' => '5511999999999@s.whatsapp.net',
                'fromMe' => false,
            ],
            'message' => [
                'messageType' => 'documentMessage',
                'documentMessage' => [
                    'mimetype' => 'application/pdf',
                    'fileName' => 'recibo.pdf',
                ],
            ],
            'documentBase64' => base64_encode($pdfLike),
            'documentMimeType' => 'application/pdf',
            'documentFileName' => 'recibo.pdf',
        ],
        'secret' => 'test-secret',
    ];

    $response = $this->postJson(route('webhook.whatsapp'), $webhookData);

    $response->assertSuccessful();
    $response->assertJson(['status' => 'queued']);

    Queue::assertPushed(ProcessWhatsAppMessage::class, function ($job) use ($user) {
        return $job->userId === $user->id
            && str_contains($job->message, 'Compra mercado');
    });
});
