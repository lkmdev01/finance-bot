<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\AIService;
use App\Services\BaileysService;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

function fakeTransactionBaileysSuccessResponse(): Response
{
    return new Response(new Psr7Response(200, [], json_encode(['success' => true])));
}

beforeEach(function () {
    $this->user = User::factory()->create([
        'phone_number' => '5513991290256',
    ]);

    $this->contact = WhatsAppContact::factory()->create([
        'user_id' => $this->user->id,
        'phone_number' => '5513991290256',
    ]);

    $this->alimentacao = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'name' => 'Alimentação',
    ]);

    $this->compras = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'name' => 'Compras',
    ]);
});

it('faz follow-up temporal de gastos por categoria', function () {
    $pastDate = now()->copy()->subMonth();

    Transaction::create([
        'user_id' => $this->user->id,
        'category_id' => $this->compras->id,
        'type' => 'expense',
        'amount' => 120.00,
        'description' => 'Compras atuais',
        'date' => now()->toDateString(),
    ]);

    Transaction::create([
        'user_id' => $this->user->id,
        'category_id' => $this->compras->id,
        'type' => 'expense',
        'amount' => 340.00,
        'description' => 'Compras do mês passado',
        'date' => $pastDate->toDateString(),
    ]);

    Http::preventStrayRequests();

    $this->contact->update([
        'conversation_state' => [
            'mode' => 'idle',
            'last_action' => 'query_category',
            'last_entities' => [
                'topic' => 'expense_category',
                'category_name' => 'Compras',
                'transaction_type' => 'expense',
                'period_scope' => 'current_month',
                'year' => now()->year,
                'month' => now()->month,
            ],
        ],
    ]);

    $previousMonthLabel = $pastDate->locale('pt_BR')->translatedFormat('F/Y');

    $this->mock(BaileysService::class, function ($mock) use ($previousMonthLabel) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) use ($previousMonthLabel) {
                return str_contains($message, 'Compras')
                    && str_contains($message, 'R$ 340,00')
                    && str_contains($message, $previousMonthLabel);
            }))
            ->andReturn(fakeTransactionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'e no mês passado?',
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
});

it('compara gastos entre categorias', function () {
    Transaction::create([
        'user_id' => $this->user->id,
        'category_id' => $this->alimentacao->id,
        'type' => 'expense',
        'amount' => 620.00,
        'description' => 'Mercado',
        'date' => now()->toDateString(),
    ]);

    Transaction::create([
        'user_id' => $this->user->id,
        'category_id' => $this->compras->id,
        'type' => 'expense',
        'amount' => 280.00,
        'description' => 'Shopping',
        'date' => now()->toDateString(),
    ]);

    Http::preventStrayRequests();

    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'reply' => 'Vou comparar essas categorias.',
                        'action' => 'query_category',
                    ]),
                ],
            ]],
        ], 200),
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'Comparando seus gastos')
                    && str_contains($message, 'Alimentação')
                    && str_contains($message, 'Compras');
            }))
            ->andReturn(fakeTransactionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'compare alimentação e compras',
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
});

it('traz sugestão útil depois de listar gastos', function () {
    Transaction::create([
        'user_id' => $this->user->id,
        'category_id' => $this->alimentacao->id,
        'type' => 'expense',
        'amount' => 120.00,
        'description' => 'Mercado',
        'date' => now()->toDateString(),
    ]);

    Transaction::create([
        'user_id' => $this->user->id,
        'category_id' => $this->compras->id,
        'type' => 'expense',
        'amount' => 90.00,
        'description' => 'Loja',
        'date' => now()->toDateString(),
    ]);

    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'reply' => 'Vou listar seus gastos.',
                        'action' => 'query_transactions',
                    ]),
                ],
            ]],
        ], 200),
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'Seus gastos de')
                    && str_contains($message, 'comparar com o mês passado')
                    && str_contains($message, 'o que mais pesou');
            }))
            ->andReturn(fakeTransactionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'quais foram meus gastos?',
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
});

it('traz insight proativo sobre categoria que mais pesou ao consultar gastos', function () {
    Transaction::create([
        'user_id' => $this->user->id,
        'category_id' => $this->alimentacao->id,
        'type' => 'expense',
        'amount' => 320.00,
        'description' => 'Mercado',
        'date' => now()->toDateString(),
    ]);

    Transaction::create([
        'user_id' => $this->user->id,
        'category_id' => $this->compras->id,
        'type' => 'expense',
        'amount' => 90.00,
        'description' => 'Loja',
        'date' => now()->toDateString(),
    ]);

    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'reply' => 'Vou listar seus gastos.',
                        'action' => 'query_transactions',
                    ]),
                ],
            ]],
        ], 200),
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'Alimentação é a categoria que mais pesou')
                    && str_contains($message, 'comparar com o mês passado');
            }))
            ->andReturn(fakeTransactionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'quais foram meus gastos?',
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
});
