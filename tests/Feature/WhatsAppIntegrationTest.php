<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\AIService;
use App\Services\BaileysService;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

function fakeBaileysSuccessResponse(): Response
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
            ->andReturn(fakeBaileysSuccessResponse());
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
            ->andReturn(fakeBaileysSuccessResponse());
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
            ->andReturn(fakeBaileysSuccessResponse());
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


it('corrige mojibake na resposta de receita via WhatsApp', function () {
    $incomeCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'name' => 'Sal?rio',
    ]);

    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => '? Registrei sua receita!',
                            'action' => 'create_transaction',
                            'transaction_data' => [
                                'type' => 'income',
                                'amount' => 1200.00,
                                'description' => 'Servi??o',
                                'category_id' => $incomeCategory->id,
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
            ->with(
                \Mockery::type('string'),
                \Mockery::on(function ($message) {
                    return str_contains($message, 'Servi?o')
                        && ! str_contains($message, 'Servi??o');
                })
            )
            ->andReturn(fakeBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Tive um ganho de 1200 em um servi?o',
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
        'amount' => 1200.00,
        'description' => 'Servi?o',
        'category_id' => $incomeCategory->id,
    ]);
});

it('processa receita simples via parser local sem depender da IA', function () {
    $incomeCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'name' => 'Sal?rio',
    ]);

    Http::fake([
        'api.groq.com/*' => Http::response([], 500),
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(
                \Mockery::type('string'),
                \Mockery::on(function ($message) {
                    return str_contains($message, 'Receita de R$ 1.200,00 registrada em Sal?rio (servi?o).')
                        || str_contains($message, 'Receita de R$ 1.200,00 registrada em Sal?rio (Servi?o).');
                })
            )
            ->andReturn(fakeBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Tive um ganho de 1200 de um servi?o',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );

    $job->handle(
        app(AIService::class),
        app(BaileysService::class),
        app(\App\Services\PhoneNumberService::class),
        app(\App\Services\PerformanceMetricsService::class),
        app(\App\Services\WhatsAppMessageProcessor::class),
        app(\App\Services\WhatsApp\ActionHandlerFactory::class)
    );

    assertDatabaseHas('transactions', [
        'user_id' => $this->user->id,
        'type' => 'income',
        'amount' => 1200.00,
        'description' => 'servi?o',
        'category_id' => $incomeCategory->id,
    ]);
});

it('processa despesa simples com forma de pagamento e data explicita', function () {
    Http::fake([
        'api.groq.com/*' => Http::response([], 500),
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(
                \Mockery::type('string'),
                \Mockery::on(function ($message) {
                    return str_contains($message, 'Registrei R$ 32,00 em Uber');
                })
            )
            ->andReturn(fakeBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Gastei 32 no Uber no d?bito ontem',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );

    $job->handle(
        app(AIService::class),
        app(BaileysService::class),
        app(\App\Services\PhoneNumberService::class),
        app(\App\Services\PerformanceMetricsService::class),
        app(\App\Services\WhatsAppMessageProcessor::class),
        app(\App\Services\WhatsApp\ActionHandlerFactory::class)
    );

    $transaction = Transaction::query()->where('user_id', $this->user->id)->latest('id')->first();

    expect($transaction)->not->toBeNull();
    expect((float) $transaction->amount)->toBe(32.0);
    expect($transaction->description)->toBe('Uber');
    expect($transaction->type)->toBe('expense');
    expect($transaction->date->toDateString())->toBe(now()->subDay()->toDateString());
    expect($transaction->metadata['payment_method'] ?? null)->toBe('debit');
});

it('processa despesa simples com data absoluta', function () {
    Http::fake([
        'api.groq.com/*' => Http::response([], 500),
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->andReturn(fakeBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Paguei 45 no mercado em 14/05/2026',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );

    $job->handle(
        app(AIService::class),
        app(BaileysService::class),
        app(\App\Services\PhoneNumberService::class),
        app(\App\Services\PerformanceMetricsService::class),
        app(\App\Services\WhatsAppMessageProcessor::class),
        app(\App\Services\WhatsApp\ActionHandlerFactory::class)
    );

    assertDatabaseHas('transactions', [
        'user_id' => $this->user->id,
        'type' => 'expense',
        'amount' => 45.00,
        'description' => 'mercado',
        'date' => '2026-05-14',
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
            ->andReturn(fakeBaileysSuccessResponse());
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
            ->andReturn(fakeBaileysSuccessResponse());
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

it('trata N/A como ausencia de descricao em gasto vago', function () {
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
                                'description' => 'N/A',
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
                    && ! str_contains($message, 'Alimentação')
                    && ! str_contains($message, 'N/A');
            }))
            ->andReturn(fakeBaileysSuccessResponse());
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

it('orienta quando o usuario manda varios lancamentos na mesma mensagem', function () {
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
                                'amount' => 10.00,
                                'description' => 'Uber',
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
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'não consigo registrar vários lançamentos')
                    && str_contains($message, 'Manda um por vez')
                    && str_contains($message, 'Gastei 32 no Uber');
            }))
            ->andReturn(fakeBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'gastei 10 no uber e 20 no mercado',
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
        'user_id' => $this->user->id,
        'amount' => 10.00,
        'description' => 'Uber',
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
            ->andReturn(fakeBaileysSuccessResponse());
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
            ->andReturn(fakeBaileysSuccessResponse());
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
            ->andReturn(fakeBaileysSuccessResponse());
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
    ))->not->toThrow(\Throwable::class);
});

it('responde saudacao com copy consistente do InovaFinance', function () {
    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => 'Oi! Como posso ajudar?',
                            'action' => null,
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
                return str_contains($message, 'InovaFinance')
                    && str_contains($message, 'registrar gastos e receitas')
                    && ! str_contains($message, 'FinanciBot');
            }))
            ->andReturn(fakeBaileysSuccessResponse());
    });

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
});

it('resume ultimos gastos com lista real', function () {
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'whatsapp_contact_id' => $this->contact->id,
        'type' => 'expense',
        'amount' => 12.50,
        'description' => 'Uber',
        'date' => now()->subDay(),
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'whatsapp_contact_id' => $this->contact->id,
        'type' => 'expense',
        'amount' => 45.90,
        'description' => 'Mercado',
        'date' => now(),
    ]);

    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => 'Ultimas transacoes:',
                            'action' => 'query_transactions',
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
                return str_contains($message, 'Seus últimos gastos:')
                    && str_contains($message, 'Mercado')
                    && str_contains($message, 'Uber')
                    && str_contains($message, 'R$ 45,90');
            }))
            ->andReturn(fakeBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'quais foram meus últimos gastos?',
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

it('resume gastos por contexto especifico sem resposta vaga', function () {
    $transportCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'name' => 'Transporte',
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'whatsapp_contact_id' => $this->contact->id,
        'type' => 'expense',
        'amount' => 18.00,
        'description' => 'Uber',
        'category_id' => $transportCategory->id,
        'date' => now()->subDay(),
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'whatsapp_contact_id' => $this->contact->id,
        'type' => 'expense',
        'amount' => 22.00,
        'description' => 'Uber',
        'category_id' => $transportCategory->id,
        'date' => now(),
    ]);

    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => 'O valor foi registrado em Transporte.',
                            'action' => 'query_category',
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
                return str_contains($message, 'Encontrei 2 gastos com Uber')
                    && str_contains($message, 'R$ 40,00')
                    && str_contains($message, 'O mais recente foi em');
            }))
            ->andReturn(fakeBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Tenho gastos com uber?',
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

it('cria orcamento via WhatsApp e salva no banco', function () {
    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => 'Vou criar esse orçamento para você.',
                            'action' => 'create_budget',
                            'transaction_data' => [
                                'category_id' => $this->category->id,
                                'amount' => 500.00,
                                'period' => 'monthly',
                                'year' => now()->year,
                                'month' => now()->month,
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
                return str_contains($message, 'Orcamento de R$ 500,00 criado');
            }))
            ->andReturn(fakeBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'criar orçamento de 500 para supermercado',
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

    assertDatabaseHas('budgets', [
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
        'amount' => 500.00,
        'period' => 'monthly',
        'year' => now()->year,
        'month' => now()->month,
    ]);
});

it('cria orcamento pelo texto do usuario mesmo sem acao especifica da IA', function () {
    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => 'Entendi seu pedido.',
                            'action' => null,
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
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'Orcamento de R$ 650,00 criado')
                    && str_contains($message, 'Supermercado');
            }))
            ->andReturn(fakeBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'criar orçamento de 650 para supermercado',
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

    assertDatabaseHas('budgets', [
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
        'amount' => 650.00,
        'period' => 'monthly',
        'year' => now()->year,
        'month' => now()->month,
    ]);
});

it('cria orcamento mesmo quando a IA envia category_id invalido mas category_name valido', function () {
    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => 'Vou registrar esse orcamento.',
                            'action' => 'create_budget',
                            'transaction_data' => [
                                'category_id' => 999999,
                                'category_name' => 'Compras',
                                'amount' => 500.00,
                                'period' => 'monthly',
                                'year' => now()->year,
                                'month' => now()->month,
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
                return str_contains($message, 'Orcamento de R$ 500,00 criado')
                    && str_contains($message, 'Compras');
            }))
            ->andReturn(fakeBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'definir orcamento de 500 para compras',
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

    $compras = Category::where('user_id', $this->user->id)
        ->where('type', 'expense')
        ->where('name', 'Compras')
        ->first();

    expect($compras)->not->toBeNull();

    assertDatabaseHas('budgets', [
        'user_id' => $this->user->id,
        'category_id' => $compras->id,
        'amount' => 500.00,
        'period' => 'monthly',
        'year' => now()->year,
        'month' => now()->month,
    ]);
});

it('consulta orcamentos via WhatsApp com dados reais do banco', function () {
    Budget::create([
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
        'amount' => 800.00,
        'period' => 'monthly',
        'year' => now()->year,
        'month' => now()->month,
    ]);

    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => 'Vou verificar seus orçamentos atuais.',
                            'action' => 'query_budgets',
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
                return str_contains($message, 'Seus orcamentos de')
                    && str_contains($message, 'Supermercado')
                    && str_contains($message, 'limite R$ 800,00');
            }))
            ->andReturn(fakeBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'quais são meus orçamentos?',
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

it('consulta orcamento por categoria via WhatsApp', function () {
    $compras = Category::create([
        'user_id' => $this->user->id,
        'name' => 'Compras',
        'type' => 'expense',
        'color' => '#E67E22',
        'icon' => '🛒',
    ]);

    Budget::create([
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
        'amount' => 800.00,
        'period' => 'monthly',
        'year' => now()->year,
        'month' => now()->month,
    ]);

    Budget::create([
        'user_id' => $this->user->id,
        'category_id' => $compras->id,
        'amount' => 500.00,
        'period' => 'monthly',
        'year' => now()->year,
        'month' => now()->month,
    ]);

    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => 'Vou verificar seu orcamento para compras.',
                            'action' => 'query_budgets',
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
                return str_contains($message, 'Compras')
                    && str_contains($message, 'limite R$ 500,00')
                    && ! str_contains($message, 'Alimentação');
            }))
            ->andReturn(fakeBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'qual meu orcamento para compras?',
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


it('responde de forma neutra para ok sem contexto pendente', function () {
    Http::preventStrayRequests();

    $this->contact->update([
        'conversation_state' => [
            'mode' => 'idle',
            'last_action' => 'query_budgets',
            'last_entities' => ['topic' => 'budget'],
        ],
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'Perfeito')
                    && ! str_contains($message, 'Bem-vindo');
            }))
            ->andReturn(fakeBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'ok',
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

    $this->contact->refresh();
    expect($this->contact->conversation_state['last_action'] ?? null)->toBe('query_budgets');
});

it('confirma transacao grande usando estado pendente sem depender da IA', function () {
    Http::preventStrayRequests();

    $this->contact->update([
        'conversation_state' => [
            'mode' => 'awaiting_confirmation',
            'pending_intent' => 'confirm_large_transaction',
            'pending_payload' => [
                'transaction_data' => [
                    'type' => 'expense',
                    'amount' => 1500.00,
                    'description' => 'Notebook',
                    'category_id' => null,
                    'date' => now()->format('Y-m-d'),
                ],
            ],
            'last_action' => 'confirm_large_transaction',
        ],
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'R$ 1.500,00');
            }))
            ->andReturn(fakeBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'ok',
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
        'amount' => 1500.00,
        'description' => 'Notebook',
    ]);

    $this->contact->refresh();
    expect($this->contact->conversation_state['mode'] ?? null)->toBe('idle');
});

it('entende follow up de orcamento apos consulta geral', function () {
    $compras = Category::create([
        'user_id' => $this->user->id,
        'name' => 'Compras',
        'type' => 'expense',
        'color' => '#E67E22',
        'icon' => '??',
    ]);

    Budget::create([
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
        'amount' => 800.00,
        'period' => 'monthly',
        'year' => now()->year,
        'month' => now()->month,
    ]);

    Budget::create([
        'user_id' => $this->user->id,
        'category_id' => $compras->id,
        'amount' => 500.00,
        'period' => 'monthly',
        'year' => now()->year,
        'month' => now()->month,
    ]);

    Http::preventStrayRequests();

    $this->contact->update([
        'conversation_state' => [
            'mode' => 'idle',
            'last_action' => 'query_budgets',
            'last_entities' => ['topic' => 'budget'],
        ],
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'Compras')
                    && str_contains($message, 'R$ 500,00')
                    && ! str_contains($message, 'Alimenta��o');
            }))
            ->andReturn(fakeBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'e compras?',
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

it('processa transacao simples com categoria explicita e cartao informado', function () {
    $creditCard = \App\Models\CreditCard::create([
        'user_id' => $this->user->id,
        'name' => 'Nubank',
        'brand' => 'Mastercard',
        'last_four' => '1234',
        'credit_limit' => 5000,
    ]);

    Http::fake([
        'api.groq.com/*' => Http::response([], 500),
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(
                \Mockery::type('string'),
                \Mockery::on(function ($message) {
                    return str_contains($message, 'Registrei R$ 89,00 em Uber');
                })
            )
            ->andReturn(fakeBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Gastei 89 no Uber no cartao Nubank categoria Transporte',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );

    $job->handle(
        app(AIService::class),
        app(BaileysService::class),
        app(\App\Services\PhoneNumberService::class),
        app(\App\Services\PerformanceMetricsService::class),
        app(\App\Services\WhatsAppMessageProcessor::class),
        app(\App\Services\WhatsApp\ActionHandlerFactory::class)
    );

    $transaction = Transaction::query()->where('user_id', $this->user->id)->latest('id')->first();

    expect($transaction)->not->toBeNull();
    expect((float) $transaction->amount)->toBe(89.0);
    expect($transaction->description)->toBe('Uber');
    expect($transaction->credit_card_id)->toBe($creditCard->id);
    expect($transaction->category?->name)->toBe('Transporte');
});

it('processa parcelado em linguagem mais solta com cartao e categoria', function () {
    $creditCard = \App\Models\CreditCard::create([
        'user_id' => $this->user->id,
        'name' => 'Nubank',
        'brand' => 'Mastercard',
        'last_four' => '1234',
        'credit_limit' => 8000,
    ]);

    Http::fake([
        'api.groq.com/*' => Http::response([], 500),
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(
                \Mockery::type('string'),
                \Mockery::on(function ($message) {
                    return str_contains($message, 'parcelado em 10x de R$ 200,00');
                })
            )
            ->andReturn(fakeBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Parcelei um celular de 2000 no cartao Nubank em 10 vezes na categoria Compras',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );

    $job->handle(
        app(AIService::class),
        app(BaileysService::class),
        app(\App\Services\PhoneNumberService::class),
        app(\App\Services\PerformanceMetricsService::class),
        app(\App\Services\WhatsAppMessageProcessor::class),
        app(\App\Services\WhatsApp\ActionHandlerFactory::class)
    );

    expect(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(10);

    $firstInstallment = Transaction::query()->where('user_id', $this->user->id)->orderBy('id')->first();
    expect($firstInstallment)->not->toBeNull();
    expect($firstInstallment->credit_card_id)->toBe($creditCard->id);
    expect($firstInstallment->category?->name)->toBe('Compras');
    expect((float) $firstInstallment->amount)->toBe(200.0);
});

it('processa recorrencia em linguagem mais humana com conta e categoria', function () {
    $bankAccount = \App\Models\BankAccount::create([
        'user_id' => $this->user->id,
        'name' => 'Itau',
        'institution' => 'Ita�',
        'opening_balance' => 0,
    ]);

    Http::fake([
        'api.groq.com/*' => Http::response([], 500),
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(
                \Mockery::type('string'),
                \Mockery::on(function ($message) {
                    return str_contains($message, 'Recorrencia criada para Internet');
                })
            )
            ->andReturn(fakeBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Minha internet e mensal, dia 12, 119 reais na conta Itau categoria Casa',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );

    $job->handle(
        app(AIService::class),
        app(BaileysService::class),
        app(\App\Services\PhoneNumberService::class),
        app(\App\Services\PerformanceMetricsService::class),
        app(\App\Services\WhatsAppMessageProcessor::class),
        app(\App\Services\WhatsApp\ActionHandlerFactory::class)
    );

    $recurring = \App\Models\RecurringTransaction::query()->where('user_id', $this->user->id)->latest('id')->first();

    expect($recurring)->not->toBeNull();
    expect((float) $recurring->amount)->toBe(119.0);
    expect($recurring->description)->toBe('Internet');
    expect($recurring->frequency)->toBe('monthly');
    expect($recurring->day_of_month)->toBe(12);
    expect($recurring->bank_account_id)->toBe($bankAccount->id);
    expect($recurring->category?->name)->toBe('Casa');
});

it('processa parcelado com data explicita', function () {
    Http::fake([
        'api.groq.com/*' => Http::response([], 500),
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Comprei um celular por 2000 em 10x em 14/05/2026',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );

    $job->handle(
        app(AIService::class),
        app(BaileysService::class),
        app(\App\Services\PhoneNumberService::class),
        app(\App\Services\PerformanceMetricsService::class),
        app(\App\Services\WhatsAppMessageProcessor::class),
        app(\App\Services\WhatsApp\ActionHandlerFactory::class)
    );

    assertDatabaseHas('transactions', [
        'user_id' => $this->user->id,
        'description' => 'Celular (1/10)',
        'date' => '2026-05-14',
        'amount' => 200.00,
    ]);
});

it('cria assinatura com cartao explicito em linguagem natural', function () {
    $creditCard = \App\Models\CreditCard::create([
        'user_id' => $this->user->id,
        'name' => 'Nubank',
        'brand' => 'Mastercard',
        'last_four' => '1234',
        'credit_limit' => 5000,
    ]);

    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Criar assinatura Netflix mensal, dia 10, 19 reais no cartao Nubank',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );

    $job->handle(
        app(AIService::class),
        app(BaileysService::class),
        app(\App\Services\PhoneNumberService::class),
        app(\App\Services\PerformanceMetricsService::class),
        app(\App\Services\WhatsAppMessageProcessor::class),
        app(\App\Services\WhatsApp\ActionHandlerFactory::class)
    );

    assertDatabaseHas('subscriptions', [
        'user_id' => $this->user->id,
        'name' => 'Netflix',
        'credit_card_id' => $creditCard->id,
    ]);
});

it('atualiza assinatura com conta explicita em linguagem natural', function () {
    $bankAccount = \App\Models\BankAccount::create([
        'user_id' => $this->user->id,
        'name' => 'Itau',
        'institution' => 'Ita�',
        'opening_balance' => 0,
    ]);

    \App\Models\Subscription::create([
        'user_id' => $this->user->id,
        'name' => 'Netflix',
        'description' => 'Assinatura: Netflix',
        'amount' => 19,
        'billing_cycle' => 'monthly',
        'due_day' => 10,
        'start_date' => now()->toDateString(),
        'auto_record' => false,
        'is_active' => true,
    ]);

    Http::preventStrayRequests();

    $this->contact->update([
        'conversation_state' => [
            'mode' => 'idle',
            'last_action' => 'query_subscriptions',
            'last_entities' => [
                'topic' => 'subscriptions',
                'subscription_name' => 'Netflix',
            ],
        ],
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'ajusta ela para 25 reais na conta Itau',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );

    $job->handle(
        app(AIService::class),
        app(BaileysService::class),
        app(\App\Services\PhoneNumberService::class),
        app(\App\Services\PerformanceMetricsService::class),
        app(\App\Services\WhatsAppMessageProcessor::class),
        app(\App\Services\WhatsApp\ActionHandlerFactory::class)
    );

    assertDatabaseHas('subscriptions', [
        'user_id' => $this->user->id,
        'name' => 'Netflix',
        'amount' => 25.00,
        'bank_account_id' => $bankAccount->id,
    ]);
});

it('interpreta valor por parcela em parcelado mais solto', function () {
    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Comprei uma moto em 12 vezes de 599',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );

    $job->handle(
        app(AIService::class),
        app(BaileysService::class),
        app(\App\Services\PhoneNumberService::class),
        app(\App\Services\PerformanceMetricsService::class),
        app(\App\Services\WhatsAppMessageProcessor::class),
        app(\App\Services\WhatsApp\ActionHandlerFactory::class)
    );

    assertDatabaseHas('transactions', [
        'user_id' => $this->user->id,
        'description' => 'Moto (1/12)',
        'amount' => 599.00,
    ]);
});

it('prioriza criacao de assinatura sobre edicao mesmo com contexto anterior', function () {
    $creditCard = \App\Models\CreditCard::create([
        'user_id' => $this->user->id,
        'name' => 'Nubank',
        'brand' => 'Mastercard',
        'last_four' => '1234',
        'credit_limit' => 5000,
    ]);

    $this->contact->update([
        'conversation_state' => [
            'mode' => 'idle',
            'last_action' => 'query_subscriptions',
            'last_entities' => [
                'topic' => 'subscriptions',
                'subscription_name' => 'Spotify',
            ],
        ],
    ]);

    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Criar assinatura Netflix mensal, dia 10, 19 reais no cartao Nubank',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );

    $job->handle(
        app(AIService::class),
        app(BaileysService::class),
        app(\App\Services\PhoneNumberService::class),
        app(\App\Services\PerformanceMetricsService::class),
        app(\App\Services\WhatsAppMessageProcessor::class),
        app(\App\Services\WhatsApp\ActionHandlerFactory::class)
    );

    assertDatabaseHas('subscriptions', [
        'user_id' => $this->user->id,
        'name' => 'Netflix',
        'amount' => 19.00,
        'credit_card_id' => $creditCard->id,
    ]);
});

it('registra cartao de credito via whatsapp em fluxo local', function () {
    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Registrar cartao de credito nubank limite de 5000',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );

    $job->handle(
        app(AIService::class),
        app(BaileysService::class),
        app(\App\Services\PhoneNumberService::class),
        app(\App\Services\PerformanceMetricsService::class),
        app(\App\Services\WhatsAppMessageProcessor::class),
        app(\App\Services\WhatsApp\ActionHandlerFactory::class)
    );

    assertDatabaseHas('credit_cards', [
        'user_id' => $this->user->id,
        'name' => 'Nubank',
        'credit_limit' => 5000.00,
    ]);
});

it('consulta cartoes ativos via whatsapp', function () {
    \App\Models\CreditCard::create([
        'user_id' => $this->user->id,
        'name' => 'Nubank',
        'brand' => 'Mastercard',
        'last_four' => '1234',
        'credit_limit' => 5000,
        'is_active' => true,
    ]);

    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'Seus cartoes ativos:')
                    && str_contains($message, 'Nubank');
            }))
            ->andReturn(fakeBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Tenho cartoes ativos?',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );

    $job->handle(
        app(AIService::class),
        app(BaileysService::class),
        app(\App\Services\PhoneNumberService::class),
        app(\App\Services\PerformanceMetricsService::class),
        app(\App\Services\WhatsAppMessageProcessor::class),
        app(\App\Services\WhatsApp\ActionHandlerFactory::class)
    );
});
