<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\AIService;
use App\Services\BaileysService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\assertDatabaseMissing;

beforeEach(function () {
    $this->user = User::factory()->create([
        'phone_number' => '5513991290256',
    ]);

    $this->contact = WhatsAppContact::factory()->create([
        'user_id' => $this->user->id,
        'phone_number' => '5513991290256',
    ]);
});

it('deleta transacao via whatsapp com confirmacao quando a IA retorna transaction_id', function () {
    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'whatsapp_contact_id' => $this->contact->id,
        'type' => 'expense',
        'amount' => 50.00,
        'description' => 'Uber',
        'date' => now()->toDateString(),
    ]);

    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => 'Ok.',
                            'action' => 'delete_transaction',
                            'transaction_data' => [
                                'transaction_id' => $transaction->id,
                            ],
                        ]),
                    ],
                ],
            ],
        ], 200),
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->twice()
            ->andReturn(new Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode(['success' => true]))));
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'apagar uber',
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

    // Segundo passo: confirma e apaga
    $confirmJob = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'sim',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );

    $confirmJob->handle(
        app(AIService::class),
        app(BaileysService::class),
        app(\App\Services\PhoneNumberService::class),
        app(\App\Services\PerformanceMetricsService::class)
    );

    assertDatabaseMissing('transactions', [
        'id' => $transaction->id,
    ]);
});

it('pede mais detalhes quando encontra mais de uma transacao parecida para apagar', function () {
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'whatsapp_contact_id' => $this->contact->id,
        'type' => 'expense',
        'amount' => 18.00,
        'description' => 'Uber',
        'date' => now()->toDateString(),
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'whatsapp_contact_id' => $this->contact->id,
        'type' => 'expense',
        'amount' => 27.00,
        'description' => 'Uber',
        'date' => now()->toDateString(),
    ]);

    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => 'Ok.',
                            'action' => 'delete_transaction',
                            'transaction_data' => [
                                'target_description' => 'uber',
                            ],
                        ]),
                    ],
                ],
            ],
        ], 200),
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->andReturn(new Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode(['success' => true]))));
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'apagar gasto com uber',
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

    $reply = mb_strtolower((string) $job->getFinalReply());

    expect($reply)->toContain('mais de uma transacao')
        ->toContain('qual delas');
    expect(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(2);
});
