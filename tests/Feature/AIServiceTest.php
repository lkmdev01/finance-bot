<?php

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\AIContextBuilder;
use App\Services\AIPromptBuilder;
use App\Services\AIResponseParser;
use App\Services\AIService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->category = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'name' => 'Supermercado',
    ]);

    $contextBuilder = new AIContextBuilder(app(\App\Services\FinancialDataCalculator::class));

    $this->aiService = new AIService(
        apiKey: config('ai.groq.api_key', 'test-key'),
        provider: 'groq',
        contextBuilder: $contextBuilder,
        promptBuilder: new AIPromptBuilder,
        responseParser: new AIResponseParser,
    );
});

it('processa mensagem de gasto corretamente', function () {
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
                                'amount' => 50.0,
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

    $result = $this->aiService->processMessage('Gastei 50 reais no supermercado', $this->user);

    expect($result['action'])->toBe('create_transaction');
    expect($result['transaction_data']['amount'])->toBe(50.0);
    expect($result['transaction_data']['type'])->toBe('expense');
    expect($result['transaction_data']['description'])->toBe('Supermercado');
    expect($result['reply'])->toContain('Registrei');
});

it('processa mensagem de receita corretamente', function () {
    $incomeCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'name' => 'Salário',
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
                                'amount' => 1000.0,
                                'description' => 'Salário',
                                'category_id' => $incomeCategory->id,
                                'date' => now()->format('Y-m-d'),
                            ],
                        ]),
                    ],
                ],
            ],
        ], 200),
    ]);

    $result = $this->aiService->processMessage('Recebi 1000 de salário', $this->user);

    expect($result['action'])->toBe('create_transaction');
    expect($result['transaction_data']['amount'])->toBe(1000.0);
    expect($result['transaction_data']['type'])->toBe('income');
    expect($result['reply'])->toContain('Registrei');
});

it('processa consulta de saldo corretamente', function () {
    // Cria algumas transações para ter contexto
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'amount' => 2000.0,
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'amount' => 500.0,
    ]);

    // Mock da resposta da IA
    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => '💰 Seu saldo disponível é R$ 1.500,00',
                            'action' => 'query_balance',
                        ]),
                    ],
                ],
            ],
        ], 200),
    ]);

    $result = $this->aiService->processMessage('Qual é o meu saldo?', $this->user);

    expect($result['action'])->toBe('query_balance');
    expect($result['reply'])->toContain('saldo');
    expect($result['transaction_data'])->toBeNull();
});

it('processa consulta de despesas corretamente', function () {
    Transaction::factory()->count(3)->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'amount' => 100.0,
    ]);

    // Mock da resposta da IA
    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => '💸 Você gastou R$ 300,00 este mês',
                            'action' => 'query_expenses',
                        ]),
                    ],
                ],
            ],
        ], 200),
    ]);

    $result = $this->aiService->processMessage('Quanto gastei este mês?', $this->user);

    expect($result['action'])->toBe('query_expenses');
    expect($result['reply'])->toContain('gastou');
    expect($result['transaction_data'])->toBeNull();
});

it('processa comando de ajuda corretamente', function () {
    // Mock da resposta da IA para comandos de ajuda
    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => '🤖 *InovaFinance - Seu Assistente Financeiro*\n\n*REGISTRAR:*\n• Gastei 50 reais no supermercado\n• Recebi 1000 de salário\n\n*CONSULTAS:*\n• Qual é o meu saldo?\n• Quanto gastei este mês?',
                            'action' => null,
                        ]),
                    ],
                ],
            ],
        ], 200),
    ]);

    $result = $this->aiService->processMessage('/ajuda', $this->user);

    expect($result['action'])->toBeNull();
    expect($result['reply'])->toContain('InovaFinance');
});

it('processa comando de ajuda com variações', function () {
    // Mock da resposta da IA
    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => '🤖 *InovaFinance - Seu Assistente Financeiro*',
                            'action' => null,
                        ]),
                    ],
                ],
            ],
        ], 200),
    ]);

    $variations = ['/help', '/comandos', 'ajuda', 'o que você faz', 'como usar'];

    foreach ($variations as $message) {
        $result = $this->aiService->processMessage($message, $this->user);

        expect($result['action'])->toBeNull();
        expect($result['reply'])->toContain('InovaFinance');
    }
});

it('solicita confirmação para transações grandes', function () {
    // Mock da resposta da IA
    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => '⚠️ Você está registrando uma transação de R$ 1.500,00. Confirma?',
                            'action' => 'confirm_large_transaction',
                            'transaction_data' => [
                                'type' => 'expense',
                                'amount' => 1500.0,
                                'description' => 'Compra grande',
                                'date' => now()->format('Y-m-d'),
                            ],
                        ]),
                    ],
                ],
            ],
        ], 200),
    ]);

    $result = $this->aiService->processMessage('Gastei 1500 reais', $this->user);

    expect($result['action'])->toBe('confirm_large_transaction');
    expect($result['transaction_data']['amount'])->toBe(1500.0);
    expect($result['reply'])->toContain('Confirma');
});

it('normaliza mensagem corretamente', function () {
    // Cria transações para contexto
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'income',
        'amount' => 1000.0,
    ]);

    // Mock da resposta da IA
    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => 'Resposta da IA',
                            'action' => 'query_balance',
                        ]),
                    ],
                ],
            ],
        ], 200),
    ]);

    // Testa normalização de diferentes formatos
    $messages = [
        'Gastei R$ 50,00 no supermercado',
        'Gastei 50 reais no supermercado',
        'Gastei R$50 no supermercado',
        'gastei cinquenta reais no supermercado',
    ];

    foreach ($messages as $message) {
        $result = $this->aiService->processMessage($message, $this->user);
        expect($result)->toHaveKey('reply');
        expect($result)->toHaveKey('action');
    }
});

it('usa contexto de contato quando disponível', function () {
    $contact = WhatsAppContact::factory()->create([
        'user_id' => $this->user->id,
        'context' => [
            [
                'message' => 'Qual é o meu saldo?',
                'reply' => 'Seu saldo é R$ 1.000,00',
                'action' => 'query_balance',
                'timestamp' => now()->subMinutes(5)->toIso8601String(),
            ],
        ],
    ]);

    // Mock da resposta da IA
    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => '💰 Seu saldo disponível é R$ 1.000,00',
                            'action' => 'query_balance',
                        ]),
                    ],
                ],
            ],
        ], 200),
    ]);

    $result = $this->aiService->processMessage('pode verificar', $this->user, $contact);

    expect($result['action'])->toBe('query_balance');
    expect($result['reply'])->toContain('saldo');
});

it('processa consulta de categorias corretamente', function () {
    Category::factory()->count(5)->create([
        'user_id' => $this->user->id,
    ]);

    // Mock da resposta da IA
    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => 'Você tem as seguintes categorias: ...',
                            'action' => 'query_categories',
                        ]),
                    ],
                ],
            ],
        ], 200),
    ]);

    $result = $this->aiService->processMessage('Quais categorias eu tenho?', $this->user);

    expect($result['action'])->toBe('query_categories');
    expect($result['reply'])->toContain('categorias');
});

it('processa consulta de transações recentes corretamente', function () {
    Transaction::factory()->count(10)->create([
        'user_id' => $this->user->id,
    ]);

    // Mock da resposta da IA
    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => 'Suas últimas transações: ...',
                            'action' => 'query_transactions',
                        ]),
                    ],
                ],
            ],
        ], 200),
    ]);

    $result = $this->aiService->processMessage('Quais foram minhas últimas transações?', $this->user);

    expect($result['action'])->toBe('query_transactions');
    expect($result['reply'])->toContain('transações');
});

it('processa consulta de gastos por categoria corretamente', function () {
    Transaction::factory()->count(3)->create([
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
        'type' => 'expense',
        'amount' => 50.0,
    ]);

    // Mock da resposta da IA
    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'reply' => 'Você gastou R$ 150,00 em Supermercado',
                            'action' => 'query_category',
                        ]),
                    ],
                ],
            ],
        ], 200),
    ]);

    $result = $this->aiService->processMessage('Quanto gastei de supermercado?', $this->user);

    expect($result['action'])->toBe('query_category');
    expect($result['reply'])->toContain('Supermercado');
});

it('lida com erros da API da IA graciosamente', function () {
    // Simula erro da API
    Http::fake([
        'api.groq.com/*' => Http::response([], 500),
    ]);

    expect(fn () => $this->aiService->processMessage('Teste', $this->user))
        ->toThrow(\RuntimeException::class);
});

it('processa mensagens com valores em diferentes formatos', function () {
    $formats = [
        'Gastei 50 reais',
        'Gastei R$ 50,00',
        'Gastei R$50',
        'Gastei cinquenta reais',
        'Gastei 50.00',
    ];

    foreach ($formats as $message) {
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'reply' => 'Registrei',
                                'action' => 'create_transaction',
                                'transaction_data' => [
                                    'type' => 'expense',
                                    'amount' => 50.0,
                                    'description' => 'Teste',
                                    'date' => now()->format('Y-m-d'),
                                ],
                            ]),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->aiService->processMessage($message, $this->user);
        expect($result['action'])->toBe('create_transaction');
    }
});
