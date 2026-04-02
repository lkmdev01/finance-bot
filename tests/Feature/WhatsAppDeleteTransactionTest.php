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

it('deleta transacao via whatsapp quando a ia retorna transaction_id', function () {
    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'whatsapp_contact_id' => $this->contact->id,
        'type' => 'expense',
        'amount' => 50.00,
        'description' => 'Uber',
    ]);

    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => '✅ Transação apagada com sucesso!',
                            'action' => 'delete_transaction',
                            'transaction_id' => $transaction->id,
                            'transaction_data' => null,
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

    assertDatabaseMissing('transactions', [
        'id' => $transaction->id,
    ]);
});
