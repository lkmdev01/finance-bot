<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\AIService;
use App\Services\BaileysService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

beforeEach(function () {
    $this->user = User::factory()->create([
        'phone_number' => '5513991290256',
    ]);
    
    $this->contact = WhatsAppContact::factory()->create([
        'user_id' => $this->user->id,
        'phone_number' => '5513991290256',
    ]);
    
    $this->category = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'name' => 'Supermercado',
    ]);
});

it('processa mensagem do WhatsApp end-to-end e cria transação', function () {
    // Mock da resposta da IA
    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => '✅ Registrei sua despesa!',
                            'action' => 'create_transaction',
                            'transaction_data' => [
                                'type' => 'expense',
                                'amount' => 50.00,
                                'description' => 'Supermercado',
                                'category_id' => $this->category->id,
                                'date' => now()->format('Y-m-d'),
                            ],
                        ]),
                    ],
                ],
            ],
        ], 200),
    ]);
    
    // Mock do BaileysService
    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->andReturn(['success' => true]);
    });
    
    // Processa a mensagem
    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Gastei 50 reais no supermercado',
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
    
    // Verifica se a transação foi criada
    assertDatabaseHas('transactions', [
        'user_id' => $this->user->id,
        'type' => 'expense',
        'amount' => 50.00,
        'description' => 'Supermercado',
        'category_id' => $this->category->id,
    ]);
});

it('processa mensagem de consulta de saldo via WhatsApp', function () {
    // Cria algumas transações para ter dados
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'amount' => 1000.00,
    ]);
    
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'amount' => 200.00,
    ]);
    
    // Mock da resposta da IA
    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => '💰 Seu saldo disponível é R$ 800,00',
                            'action' => 'query_balance',
                        ]),
                    ],
                ],
            ],
        ], 200),
    ]);
    
    // Mock do BaileysService
    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'saldo') || str_contains($message, 'R$');
            }))
            ->andReturn(['success' => true]);
    });
    
    // Processa a mensagem
    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Qual é o meu saldo?',
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
    
    // Verifica se o contexto foi atualizado
    $this->contact->refresh();
    expect($this->contact->context)->toBeArray();
    expect(count($this->contact->context))->toBeGreaterThan(0);
});

it('processa mensagem de receita via WhatsApp', function () {
    $incomeCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'name' => 'Serviços',
    ]);
    
    // Mock da resposta da IA
    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => '✅ Registrei sua receita!',
                            'action' => 'create_transaction',
                            'transaction_data' => [
                                'type' => 'income',
                                'amount' => 500.00,
                                'description' => 'Serviço',
                                'category_id' => $incomeCategory->id,
                                'date' => now()->format('Y-m-d'),
                            ],
                        ]),
                    ],
                ],
            ],
        ], 200),
    ]);
    
    // Mock do BaileysService
    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->andReturn(['success' => true]);
    });
    
    // Processa a mensagem
    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Recebi 500 reais de serviço',
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
    
    // Verifica se a transação foi criada
    assertDatabaseHas('transactions', [
        'user_id' => $this->user->id,
        'type' => 'income',
        'amount' => 500.00,
        'description' => 'Serviço',
        'category_id' => $incomeCategory->id,
    ]);
});

it('cria receita via WhatsApp mesmo quando a IA retorna descricao vazia', function () {
    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => '✅ Receita de R$ 420,00 registrada!',
                            'action' => 'create_transaction',
                            'transaction_data' => [
                                'type' => 'income',
                                'amount' => 420.00,
                                'description' => null,
                                'category_id' => null,
                                'date' => now()->format('Y-m-d'),
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
            ->andReturn(['success' => true]);
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Recebi 420',
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

    assertDatabaseHas('transactions', [
        'user_id' => $this->user->id,
        'type' => 'income',
        'amount' => 420.00,
        'description' => 'Receita',
        'category_id' => null,
    ]);
});

it('nao inventa categoria para gasto vago sem descricao', function () {
    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => '✅ Registrado: R$ 1,00 em Alimentação (N/A)',
                            'action' => 'create_transaction',
                            'transaction_data' => [
                                'type' => 'expense',
                                'amount' => 1.00,
                                'description' => null,
                                'category_id' => null,
                                'category_name' => 'Alimentação',
                                'date' => now()->format('Y-m-d'),
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
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'Gasto de R$ 1,00 registrado!')
                    && ! str_contains($message, 'Alimentação');
            }))
            ->andReturn(new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode(['success' => true]))));
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'gastei 1',
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

    assertDatabaseHas('transactions', [
        'user_id' => $this->user->id,
        'type' => 'expense',
        'amount' => 1.00,
        'description' => 'Gasto',
        'category_id' => null,
    ]);
});

it('reutiliza categoria existente equivalente antes de criar uma nova', function () {
    $existingCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'name' => 'Mercado',
    ]);

    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => '✅ Registrado: R$ 32,00 em Alimentação (Burger King)',
                            'action' => 'create_transaction',
                            'transaction_data' => [
                                'type' => 'expense',
                                'amount' => 32.00,
                                'description' => 'Burger King',
                                'category_id' => null,
                                'category_name' => 'Alimentação',
                                'date' => now()->format('Y-m-d'),
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
            ->andReturn(new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode(['success' => true]))));
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'gastei 32 no burger king',
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

    $transaction = Transaction::query()
        ->where('user_id', $this->user->id)
        ->where('amount', 32.00)
        ->where('description', 'Burger King')
        ->latest('id')
        ->first();

    expect($transaction)->not->toBeNull();
    expect([$this->category->id, $existingCategory->id])->toContain($transaction->category_id);

    expect(Category::where('user_id', $this->user->id)->where('name', 'Alimentação')->count())->toBe(0);
});

it('atualiza contexto do contato após processar mensagem', function () {
    // Mock da resposta da IA
    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => 'Olá! Como posso ajudar?',
                            'action' => null,
                        ]),
                    ],
                ],
            ],
        ], 200),
    ]);
    
    // Mock do BaileysService
    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->andReturn(['success' => true]);
    });
    
    $initialContextCount = count($this->contact->context ?? []);
    
    // Processa a mensagem
    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Oi',
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
    
    // Verifica se o contexto foi atualizado
    $this->contact->refresh();
    expect($this->contact->context)->toBeArray();
    expect(count($this->contact->context))->toBe($initialContextCount + 1);
    expect($this->contact->context[count($this->contact->context) - 1]['message'])->toBe('Oi');
});

it('lida com erro na API da IA graciosamente', function () {
    // Mock de erro na API
    Http::fake([
        'api.groq.com/*' => Http::response([], 500),
    ]);
    
    // Mock do BaileysService
    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'erro') || str_contains($message, 'desculpe');
            }))
            ->andReturn(['success' => true]);
    });
    
    // Processa a mensagem
    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Qual é o meu saldo?',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    
    // Não deve lançar exceção
    expect(fn() => $job->handle(
        app(AIService::class),
        app(BaileysService::class),
        app(\App\Services\PhoneNumberService::class),
        app(\App\Services\PerformanceMetricsService::class)
    ))->not->toThrow();
});
