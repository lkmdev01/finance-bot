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

it('resume gastos do periodo inteiro e destaca lancamentos sem categoria', function () {
    $marketing = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'name' => 'Marketing',
    ]);

    foreach ([
        [null, 100.00, 'Transacao'],
        [null, 79.00, 'Transacao'],
        [$this->compras->id, 260.00, 'Internos'],
        [$marketing->id, 355.00, 'Marketing'],
        [$this->alimentacao->id, 45.00, 'Almoco'],
        [$this->alimentacao->id, 25.00, 'Cafe'],
    ] as [$categoryId, $amount, $description]) {
        Transaction::create([
            'user_id' => $this->user->id,
            'category_id' => $categoryId,
            'type' => 'expense',
            'amount' => $amount,
            'description' => $description,
            'date' => now()->toDateString(),
        ]);
    }

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
                return str_contains($message, 'Total no')
                    && str_contains($message, 'R$ 864,00')
                    && str_contains($message, 'Mostrei os 5')
                    && str_contains($message, 'Resumo por categoria')
                    && str_contains($message, 'Sem categoria R$ 179,00')
                    && str_contains($message, 'sem categoria')
                    && str_contains($message, 'Ver tudo no dashboard')
                    && str_contains($message, route('dashboard'));
            }))
            ->andReturn(fakeTransactionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'resumo de gastos',
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

it('filtra gastos sem categoria quando usuario pede explicitamente', function () {
    Transaction::create([
        'user_id' => $this->user->id,
        'category_id' => null,
        'type' => 'expense',
        'amount' => 100.00,
        'description' => 'Transacao sem categoria',
        'date' => now()->toDateString(),
    ]);

    Transaction::create([
        'user_id' => $this->user->id,
        'category_id' => $this->compras->id,
        'type' => 'expense',
        'amount' => 260.00,
        'description' => 'Compra categorizada',
        'date' => now()->toDateString(),
    ]);

    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'Seus gastos sem categoria')
                    && str_contains($message, 'R$ 100,00')
                    && str_contains($message, 'Transacao sem categoria')
                    && str_contains($message, 'Sem categoria R$ 100,00')
                    && str_contains($message, route('dashboard'))
                    && ! str_contains($message, 'Compra categorizada')
                    && ! str_contains($message, 'R$ 260,00');
            }))
            ->andReturn(fakeTransactionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'quais gastos sem categoria esse mes',
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

it('filtra gastos com categoria quando usuario pede explicitamente', function () {
    Transaction::create([
        'user_id' => $this->user->id,
        'category_id' => null,
        'type' => 'expense',
        'amount' => 100.00,
        'description' => 'Transacao sem categoria',
        'date' => now()->toDateString(),
    ]);

    Transaction::create([
        'user_id' => $this->user->id,
        'category_id' => $this->compras->id,
        'type' => 'expense',
        'amount' => 260.00,
        'description' => 'Compra categorizada',
        'date' => now()->toDateString(),
    ]);

    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'Seus gastos com categoria')
                    && str_contains($message, 'R$ 260,00')
                    && str_contains($message, 'Compra categorizada')
                    && str_contains($message, 'Compras R$ 260,00')
                    && str_contains($message, route('dashboard'))
                    && ! str_contains($message, 'Transacao sem categoria')
                    && ! str_contains($message, 'R$ 100,00');
            }))
            ->andReturn(fakeTransactionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'quais gastos com categoria esse mes',
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

it('consulta gastos por intervalo customizado entre dois meses', function () {
    \Illuminate\Support\Carbon::setTestNow('2026-08-03 10:00:00');

    try {
        foreach ([
            ['2026-07-15', 999.00, 'Fora antes'],
            ['2026-07-16', 100.00, 'Inicio intervalo'],
            ['2026-07-31', 79.00, 'Meio intervalo'],
            ['2026-08-02', 50.00, 'Fim intervalo'],
            ['2026-08-03', 888.00, 'Fora depois'],
        ] as [$date, $amount, $description]) {
            Transaction::create([
                'user_id' => $this->user->id,
                'category_id' => $this->compras->id,
                'type' => 'expense',
                'amount' => $amount,
                'description' => $description,
                'date' => $date,
            ]);
        }

        Http::preventStrayRequests();

        $this->mock(BaileysService::class, function ($mock) {
            $mock->shouldReceive('sendTextMessage')
                ->once()
                ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                    return str_contains($message, 'de 16/07/2026 a 02/08/2026')
                        && str_contains($message, 'R$ 229,00')
                        && str_contains($message, 'Inicio intervalo')
                        && str_contains($message, 'Meio intervalo')
                        && str_contains($message, 'Fim intervalo')
                        && ! str_contains($message, 'Fora antes')
                        && ! str_contains($message, 'Fora depois')
                        && str_contains($message, route('dashboard'));
                }))
                ->andReturn(fakeTransactionBaileysSuccessResponse());
        });

        $job = new ProcessWhatsAppMessage(
            phoneNumber: '5513991290256',
            message: 'quais gastos do dia 16 do mes 7 a dia 2 do mes 8',
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
    } finally {
        \Illuminate\Support\Carbon::setTestNow();
    }
});
