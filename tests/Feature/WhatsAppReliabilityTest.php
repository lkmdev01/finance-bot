<?php

use App\Jobs\DeliverWhatsAppReply;
use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppMessageReceipt;
use App\Models\WhatsAppOutboundMessage;
use App\Services\AIService;
use App\Services\BaileysService;
use App\Services\PerformanceMetricsService;
use App\Services\PhoneNumberService;
use App\Services\WhatsApp\ActionHandlerFactory;
use App\Services\WhatsApp\WhatsAppReplyDelivery;
use App\Services\WhatsAppMessageProcessor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

it('queues a provider message only once, even when the webhook is redelivered', function () {
    Queue::fake();
    config(['whatsapp.baileys.webhook_secret' => 'test-secret']);

    User::factory()->create([
        'phone_number' => '5511999999999',
        'whatsapp_verified_at' => now(),
    ]);

    $payload = [
        'event' => 'messages.upsert',
        'secret' => 'test-secret',
        'data' => [
            'key' => [
                'id' => 'provider-message-123',
                'remoteJid' => '5511999999999@s.whatsapp.net',
                'fromMe' => false,
            ],
            'message' => [
                'conversation' => 'Gastei 50 no mercado',
            ],
        ],
    ];

    $this->postJson(route('webhook.whatsapp'), $payload)->assertOk()->assertJson(['status' => 'queued']);
    $this->postJson(route('webhook.whatsapp'), $payload)->assertOk()->assertJson(['status' => 'duplicate']);

    Queue::assertPushed(ProcessWhatsAppMessage::class, 1);
    expect(WhatsAppMessageReceipt::count())->toBe(1)
        ->and(WhatsAppMessageReceipt::firstOrFail()->status)->toBe(WhatsAppMessageReceipt::RECEIVED);
});

it('rejects a missing webhook secret and never logs supplied secrets', function () {
    config(['whatsapp.baileys.webhook_secret' => 'expected-secret']);
    Log::spy();

    $this->postJson(route('webhook.whatsapp'), [
        'event' => 'messages.upsert',
        'secret' => 'wrong-secret',
    ])->assertUnauthorized();

    Log::shouldHaveReceived('warning')
        ->with('Webhook recebido com secret invalido')
        ->once();

    config(['whatsapp.baileys.webhook_secret' => null]);
    $this->postJson(route('webhook.whatsapp'), [
        'event' => 'messages.upsert',
    ])->assertUnauthorized();
});

it('completes the receipt and persists replies for terminal webhook flows', function () {
    Queue::fake();
    Http::fake(['*/send-message' => Http::response(['success' => true], 200)]);
    config(['whatsapp.baileys.webhook_secret' => 'test-secret']);

    $this->postJson(route('webhook.whatsapp'), [
        'event' => 'messages.upsert',
        'secret' => 'test-secret',
        'data' => [
            'key' => [
                'id' => 'unknown-user-message',
                'remoteJid' => '5511888888888@s.whatsapp.net',
                'fromMe' => false,
            ],
            'message' => ['conversation' => 'Oi'],
        ],
    ])->assertOk()->assertJson(['status' => 'no_user']);

    expect(WhatsAppMessageReceipt::firstOrFail()->status)->toBe(WhatsAppMessageReceipt::COMPLETED)
        ->and(WhatsAppMessageReceipt::firstOrFail()->completed_at)->not->toBeNull()
        ->and(WhatsAppOutboundMessage::firstOrFail()->status)->toBe('delivered');
    Http::assertSentCount(1);
});

it('persists a failed reply and retries only its delivery', function () {
    Queue::fake([DeliverWhatsAppReply::class]);
    Http::fake([
        '*/send-message' => Http::sequence()
            ->push(['error' => 'WhatsApp não está conectado'], 503)
            ->push(['success' => true], 200),
    ]);

    $delivery = app(WhatsAppReplyDelivery::class);
    $delivery->send('5511999999999@s.whatsapp.net', 'Despesa registrada.', null);

    $outbound = WhatsAppOutboundMessage::firstOrFail();
    expect($outbound->status)->toBe('pending')
        ->and($outbound->attempts)->toBe(1)
        ->and($outbound->body)->toBe('Despesa registrada.')
        ->and($outbound->recipient_jid)->toBe('5511999999999@s.whatsapp.net')
        ->and(DB::table('whats_app_outbound_messages')->value('recipient_jid'))->not->toBe('5511999999999@s.whatsapp.net')
        ->and(DB::table('whats_app_outbound_messages')->value('body'))->not->toBe('Despesa registrada.');
    Queue::assertPushed(DeliverWhatsAppReply::class, 1);

    $job = new DeliverWhatsAppReply($outbound->id);
    $job->handle($delivery);

    expect($outbound->fresh()->status)->toBe('delivered')
        ->and($outbound->fresh()->attempts)->toBe(2)
        ->and($outbound->fresh()->delivered_at)->not->toBeNull();
    Http::assertSentCount(2);

    $job->handle($delivery);
    Http::assertSentCount(2);
});

it('does not repeat a financial action when its queued job is executed twice', function () {
    Queue::fake([DeliverWhatsAppReply::class]);
    Http::fake(['*/send-message' => Http::response(['error' => 'WhatsApp não está conectado'], 503)]);

    $user = User::factory()->create(['phone_number' => '5511999999999']);
    $eventKey = hash('sha256', '5511999999999@s.whatsapp.net'."\0"."\0".'message-once');
    WhatsAppMessageReceipt::create(['event_key' => $eventKey]);

    $processor = Mockery::mock(WhatsAppMessageProcessor::class);
    $processor->shouldReceive('process')->once()->andReturn([
        'action' => 'create_transaction',
        'reply' => '',
        'transaction_data' => [
            'type' => 'expense',
            'amount' => 25,
            'description' => 'Uber',
            'date' => now()->toDateString(),
        ],
    ]);

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5511999999999',
        message: 'Gastei 25 no Uber',
        userId: $user->id,
        remoteJid: '5511999999999@s.whatsapp.net',
        inboundEventKey: $eventKey,
    );

    $run = fn () => $job->handle(
        app(AIService::class),
        app(BaileysService::class),
        app(PhoneNumberService::class),
        app(PerformanceMetricsService::class),
        $processor,
        app(ActionHandlerFactory::class),
    );

    $run();
    $run();

    expect(Transaction::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(WhatsAppMessageReceipt::firstOrFail()->completed_at)->not->toBeNull()
        ->and(WhatsAppMessageReceipt::firstOrFail()->status)->toBe(WhatsAppMessageReceipt::COMPLETED)
        ->and(WhatsAppOutboundMessage::count())->toBe(1);
    Queue::assertPushed(DeliverWhatsAppReply::class, 1);
});

it('claims an outbound message atomically so concurrent attempts do not send twice', function () {
    Http::fake(['*/send-message' => Http::response(['success' => true], 200)]);
    $outbound = WhatsAppOutboundMessage::create([
        'recipient_jid' => '5511999999999@s.whatsapp.net',
        'body' => 'Resposta reservada',
        'status' => 'sending',
    ]);

    expect(app(WhatsAppReplyDelivery::class)->attempt($outbound))->toBeTrue();
    Http::assertNothingSent();
    expect($outbound->fresh()->status)->toBe('sending');
});

it('holds uncertain gateway failures for review instead of resending automatically', function () {
    Queue::fake([DeliverWhatsAppReply::class]);
    Http::fake(['*/send-message' => Http::response(['error' => 'provider failure'], 500)]);

    app(WhatsAppReplyDelivery::class)->send('5511999999999@s.whatsapp.net', 'Resposta possivelmente entregue');

    $outbound = WhatsAppOutboundMessage::firstOrFail();
    expect($outbound->status)->toBe('needs_review')
        ->and($outbound->attempts)->toBe(1);
    Queue::assertNothingPushed();
    Http::assertSentCount(1);

    (new DeliverWhatsAppReply($outbound->id))->handle(app(WhatsAppReplyDelivery::class));
    Http::assertSentCount(1);
});

it('marks stale work for review and never retries an ambiguous delivery automatically', function () {
    Queue::fake();
    Http::fake();

    $receipt = WhatsAppMessageReceipt::create([
        'event_key' => hash('sha256', 'stale-inbound'),
        'status' => WhatsAppMessageReceipt::PROCESSING,
        'processing_started_at' => now()->subMinutes(20),
    ]);
    $outbound = WhatsAppOutboundMessage::create([
        'recipient_jid' => '5511999999999@s.whatsapp.net',
        'body' => 'Resposta possivelmente enviada',
        'status' => 'sending',
    ]);
    $outbound->forceFill(['updated_at' => now()->subMinutes(3)])->save();

    expect(Artisan::call('whatsapp:reliability', ['--mark-stale' => true]))->toBe(0)
        ->and($receipt->fresh()->status)->toBe(WhatsAppMessageReceipt::NEEDS_REVIEW)
        ->and($outbound->fresh()->status)->toBe('needs_review');
    Queue::assertNothingPushed();
    Http::assertNothingSent();

    expect(Artisan::call('whatsapp:reliability', ['--retry-outbound' => (string) $outbound->id]))->toBe(1);
    Queue::assertNothingPushed();

    expect(Artisan::call('whatsapp:reliability', [
        '--retry-outbound' => (string) $outbound->id,
        '--allow-ambiguous' => true,
    ]))->toBe(0);
    Queue::assertPushed(DeliverWhatsAppReply::class, 1);
    expect($outbound->fresh()->status)->toBe('pending');
});
