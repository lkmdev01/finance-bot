<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversationLog;
use App\Services\AIService;
use App\Services\BaileysService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create([
        'phone_number' => '5513991290256',
    ]);

    $this->contact = WhatsAppContact::factory()->create([
        'user_id' => $this->user->id,
        'phone_number' => '5513991290256',
    ]);
});

it('records assistant intent metadata in whatsapp conversation logs', function () {
    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->atLeast()->once()
            ->andReturn(new Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode(['success' => true]))));
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'qual e meu saldo?',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );

    $job->handle(
        app(AIService::class),
        app(BaileysService::class),
        app(\App\Services\PhoneNumberService::class),
        app(\App\Services\PerformanceMetricsService::class)
    );

    $log = WhatsAppConversationLog::query()->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->metadata['assistant_intent'] ?? null)->toBe('query_balance')
        ->and($log->metadata['assistant_confidence'] ?? null)->toBeFloat()
        ->and($log->metadata['assistant_domain'] ?? null)->toBe('transaction')
        ->and($log->metadata['assistant_used_ai'] ?? null)->toBeFalse();
});
