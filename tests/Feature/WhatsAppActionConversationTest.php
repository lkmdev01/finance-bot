<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Budget;
use App\Models\Category;
use App\Models\RecurringTransaction;
use App\Models\Reminder;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversationLog;
use App\Services\AIService;
use App\Services\BaileysService;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

function fakeActionBaileysSuccessResponse(): Response
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

    $this->compras = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'name' => 'Compras',
    ]);
});

it('edita orcamento por contexto recente', function () {
    $budget = Budget::create([
        'user_id' => $this->user->id,
        'category_id' => $this->compras->id,
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
            'last_entities' => [
                'topic' => 'budget',
                'budget_id' => $budget->id,
                'category_name' => 'Compras',
                'year' => now()->year,
                'month' => now()->month,
            ],
        ],
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'Orcamento de Compras atualizado para R$ 700,00');
            }))
            ->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'ajusta para 700',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );

    $job->handle(app(AIService::class), app(BaileysService::class), app(\App\Services\PhoneNumberService::class), app(\App\Services\PerformanceMetricsService::class));

    assertDatabaseHas('budgets', [
        'id' => $budget->id,
        'amount' => 700.00,
    ]);
});

it('pede confirmacao antes de cancelar orcamento e apaga ao confirmar', function () {
    $budget = Budget::create([
        'user_id' => $this->user->id,
        'category_id' => $this->compras->id,
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
            'last_entities' => [
                'topic' => 'budget',
                'budget_id' => $budget->id,
                'category_name' => 'Compras',
                'year' => now()->year,
                'month' => now()->month,
            ],
        ],
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->twice()
            ->withArgs(function ($jid, $message) {
                return is_string($jid) && is_string($message);
            })
            ->andReturn(fakeActionBaileysSuccessResponse());
    });

    $firstJob = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'cancela esse orcamento',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    $firstJob->handle(app(AIService::class), app(BaileysService::class), app(\App\Services\PhoneNumberService::class), app(\App\Services\PerformanceMetricsService::class));

    $this->contact->refresh();
    expect($this->contact->conversation_state['pending_intent'] ?? null)->toBe('delete_budget');

    $confirmJob = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'sim',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    $confirmJob->handle(app(AIService::class), app(BaileysService::class), app(\App\Services\PhoneNumberService::class), app(\App\Services\PerformanceMetricsService::class));

    assertDatabaseMissing('budgets', [
        'id' => $budget->id,
    ]);
});

it('edita transacao por contexto recente', function () {
    $transaction = Transaction::create([
        'user_id' => $this->user->id,
        'whatsapp_contact_id' => $this->contact->id,
        'category_id' => $this->compras->id,
        'type' => 'expense',
        'amount' => 20.00,
        'description' => 'Uber',
        'date' => now()->toDateString(),
    ]);

    Http::preventStrayRequests();

    $this->contact->update([
        'conversation_state' => [
            'mode' => 'idle',
            'last_action' => 'query_transactions',
            'last_entities' => [
                'topic' => 'transactions',
                'transaction_id' => $transaction->id,
                'latest_transaction_id' => $transaction->id,
                'latest_transaction_description' => 'Uber',
                'transaction_type' => 'expense',
                'category_name' => 'Compras',
            ],
        ],
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'Atualizei Uber para R$ 28,00');
            }))
            ->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'ajusta para 28',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );

    $job->handle(app(AIService::class), app(BaileysService::class), app(\App\Services\PhoneNumberService::class), app(\App\Services\PerformanceMetricsService::class));

    assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'amount' => 28.00,
    ]);
});

it('pede confirmacao antes de apagar transacao contextual e remove ao confirmar', function () {
    $transaction = Transaction::create([
        'user_id' => $this->user->id,
        'whatsapp_contact_id' => $this->contact->id,
        'category_id' => $this->compras->id,
        'type' => 'expense',
        'amount' => 20.00,
        'description' => 'Uber',
        'date' => now()->toDateString(),
    ]);

    Http::preventStrayRequests();

    $this->contact->update([
        'conversation_state' => [
            'mode' => 'idle',
            'last_action' => 'query_transactions',
            'last_entities' => [
                'topic' => 'transactions',
                'transaction_id' => $transaction->id,
                'latest_transaction_id' => $transaction->id,
                'latest_transaction_description' => 'Uber',
                'transaction_type' => 'expense',
                'category_name' => 'Compras',
            ],
        ],
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->twice()
            ->withArgs(function ($jid, $message) {
                return is_string($jid) && is_string($message);
            })
            ->andReturn(fakeActionBaileysSuccessResponse());
    });

    $firstJob = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'apaga essa',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    $firstJob->handle(app(AIService::class), app(BaileysService::class), app(\App\Services\PhoneNumberService::class), app(\App\Services\PerformanceMetricsService::class));

    $this->contact->refresh();
    expect($this->contact->conversation_state['pending_intent'] ?? null)->toBe('delete_transaction');

    $confirmJob = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'sim',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    $confirmJob->handle(app(AIService::class), app(BaileysService::class), app(\App\Services\PhoneNumberService::class), app(\App\Services\PerformanceMetricsService::class));

    assertDatabaseMissing('transactions', [
        'id' => $transaction->id,
    ]);
});

it('registra lancamentos compostos na mesma mensagem', function () {
    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'reply' => 'Vou registrar seus gastos.',
                        'action' => 'create_transaction',
                        'transaction_data' => [
                            'type' => 'expense',
                            'amount' => 20.0,
                            'description' => 'Uber',
                            'date' => now()->format('Y-m-d'),
                        ],
                    ]),
                ],
            ]],
        ], 200),
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'Registrei estes lancamentos')
                    && str_contains($message, 'Uber: R$ 20,00')
                    && str_contains($message, 'mercado: R$ 35,00');
            }))
            ->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'gastei 20 no Uber e 35 no mercado',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );

    $job->handle(app(AIService::class), app(BaileysService::class), app(\App\Services\PhoneNumberService::class), app(\App\Services\PerformanceMetricsService::class));

    expect(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(2);
    assertDatabaseHas('transactions', [
        'user_id' => $this->user->id,
        'amount' => 20.00,
        'description' => 'Uber',
    ]);
    assertDatabaseHas('transactions', [
        'user_id' => $this->user->id,
        'amount' => 35.00,
        'description' => 'mercado',
    ]);
});

it('apaga o penultimo gasto por referencia curta', function () {
    Http::preventStrayRequests();

    $first = Transaction::create([
        'user_id' => $this->user->id,
        'whatsapp_contact_id' => $this->contact->id,
        'category_id' => $this->compras->id,
        'type' => 'expense',
        'amount' => 20.00,
        'description' => 'Uber',
        'date' => now()->subDay()->toDateString(),
    ]);

    $second = Transaction::create([
        'user_id' => $this->user->id,
        'whatsapp_contact_id' => $this->contact->id,
        'category_id' => $this->compras->id,
        'type' => 'expense',
        'amount' => 35.00,
        'description' => 'Mercado',
        'date' => now()->toDateString(),
    ]);

    $this->contact->update([
        'conversation_state' => [
            'mode' => 'idle',
            'last_action' => 'query_transactions',
            'last_entities' => [
                'topic' => 'transactions',
                'latest_transaction_ids' => [$second->id, $first->id],
                'latest_transaction_id' => $second->id,
            ],
        ],
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->twice()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'apaga o penultimo',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    $job->handle(app(AIService::class), app(BaileysService::class), app(\App\Services\PhoneNumberService::class), app(\App\Services\PerformanceMetricsService::class));

    $confirm = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'sim',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    $confirm->handle(app(AIService::class), app(BaileysService::class), app(\App\Services\PhoneNumberService::class), app(\App\Services\PerformanceMetricsService::class));

    assertDatabaseMissing('transactions', ['id' => $first->id]);
    assertDatabaseHas('transactions', ['id' => $second->id]);
});

it('permite completar edicao contextual de ontem em duas mensagens', function () {
    Http::preventStrayRequests();

    $transaction = Transaction::create([
        'user_id' => $this->user->id,
        'whatsapp_contact_id' => $this->contact->id,
        'category_id' => $this->compras->id,
        'type' => 'expense',
        'amount' => 22.00,
        'description' => 'Almoco',
        'date' => now()->subDay()->toDateString(),
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->twice()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $first = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'corrige o de ontem',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    $first->handle(app(AIService::class), app(BaileysService::class), app(\App\Services\PhoneNumberService::class), app(\App\Services\PerformanceMetricsService::class));

    $this->contact->refresh();
    expect($this->contact->conversation_state['pending_intent'] ?? null)->toBe('edit_transaction_details');

    $second = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'para 28',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    $second->handle(app(AIService::class), app(BaileysService::class), app(\App\Services\PhoneNumberService::class), app(\App\Services\PerformanceMetricsService::class));

    assertDatabaseHas('transactions', ['id' => $transaction->id, 'amount' => 28.00]);
});

it('marca o ultimo gasto como debito por follow-up curto', function () {
    Http::preventStrayRequests();

    $transaction = Transaction::create([
        'user_id' => $this->user->id,
        'whatsapp_contact_id' => $this->contact->id,
        'category_id' => $this->compras->id,
        'type' => 'expense',
        'amount' => 18.00,
        'description' => 'Padaria',
        'date' => now()->toDateString(),
        'metadata' => [],
    ]);

    $this->contact->update([
        'conversation_state' => [
            'mode' => 'idle',
            'last_action' => 'query_transactions',
            'last_entities' => [
                'topic' => 'transactions',
                'transaction_id' => $transaction->id,
                'latest_transaction_id' => $transaction->id,
            ],
        ],
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'esse foi no debito',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    $job->handle(app(AIService::class), app(BaileysService::class), app(\App\Services\PhoneNumberService::class), app(\App\Services\PerformanceMetricsService::class));

    expect(Transaction::find($transaction->id)?->metadata['payment_method'] ?? null)->toBe('debit');
});

it('cria recorrencia via whatsapp', function () {
    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'todo dia 5 pago academia 89',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    $job->handle(app(AIService::class), app(BaileysService::class), app(\App\Services\PhoneNumberService::class), app(\App\Services\PerformanceMetricsService::class));

    assertDatabaseHas('recurring_transactions', [
        'user_id' => $this->user->id,
        'amount' => 89.00,
        'description' => 'Academia',
        'frequency' => 'monthly',
        'day_of_month' => 5,
    ]);
});

it('cria recorrencia mensal com valor e dia sem confundir o dia com o valor', function () {
    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Todo mes pagar academia 100 reais dia 10',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    $job->handle(app(AIService::class), app(BaileysService::class), app(\App\Services\PhoneNumberService::class), app(\App\Services\PerformanceMetricsService::class));

    assertDatabaseHas('recurring_transactions', [
        'user_id' => $this->user->id,
        'amount' => 100.00,
        'description' => 'Academia',
        'frequency' => 'monthly',
        'day_of_month' => 10,
    ]);
});

it('edita recorrencia por contexto recente', function () {
    Http::preventStrayRequests();

    $recurring = RecurringTransaction::create([
        'user_id' => $this->user->id,
        'category_id' => $this->compras->id,
        'type' => 'expense',
        'amount' => 89.00,
        'description' => 'Academia',
        'frequency' => 'monthly',
        'start_date' => now()->toDateString(),
        'is_active' => true,
        'day_of_month' => 5,
    ]);

    $this->contact->update([
        'conversation_state' => [
            'mode' => 'idle',
            'last_action' => 'create_recurring_transaction',
            'last_entities' => [
                'topic' => 'recurring_transactions',
                'recurring_transaction_id' => $recurring->id,
                'recurring_description' => 'Academia',
            ],
        ],
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'ajusta para 99',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    $job->handle(app(AIService::class), app(BaileysService::class), app(\App\Services\PhoneNumberService::class), app(\App\Services\PerformanceMetricsService::class));

    assertDatabaseHas('recurring_transactions', [
        'id' => $recurring->id,
        'amount' => 99.00,
    ]);
});

it('cancela recorrencia por contexto recente', function () {
    Http::preventStrayRequests();

    $recurring = RecurringTransaction::create([
        'user_id' => $this->user->id,
        'category_id' => $this->compras->id,
        'type' => 'expense',
        'amount' => 89.00,
        'description' => 'Academia',
        'frequency' => 'monthly',
        'start_date' => now()->toDateString(),
        'is_active' => true,
        'day_of_month' => 5,
    ]);

    $this->contact->update([
        'conversation_state' => [
            'mode' => 'idle',
            'last_action' => 'create_recurring_transaction',
            'last_entities' => [
                'topic' => 'recurring_transactions',
                'recurring_transaction_id' => $recurring->id,
                'recurring_description' => 'Academia',
            ],
        ],
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'cancela ela',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    $job->handle(app(AIService::class), app(BaileysService::class), app(\App\Services\PhoneNumberService::class), app(\App\Services\PerformanceMetricsService::class));

    assertDatabaseHas('recurring_transactions', [
        'id' => $recurring->id,
        'is_active' => false,
    ]);
});

it('cria parcelamento via whatsapp', function () {
    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'comprei celular por 2000 em 10x',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    $job->handle(app(AIService::class), app(BaileysService::class), app(\App\Services\PhoneNumberService::class), app(\App\Services\PerformanceMetricsService::class));

    expect(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(10);
    assertDatabaseHas('transactions', [
        'user_id' => $this->user->id,
        'amount' => 200.00,
        'description' => 'Celular (1/10)',
    ]);
});

it('registra log estruturado da conversa', function () {
    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'oi',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    $job->handle(app(AIService::class), app(BaileysService::class), app(\App\Services\PhoneNumberService::class), app(\App\Services\PerformanceMetricsService::class));

    assertDatabaseHas('whats_app_conversation_logs', [
        'user_id' => $this->user->id,
        'message' => 'oi',
        'classification' => 'greeting',
        'status' => 'handled_preflight',
    ]);

    expect(WhatsAppConversationLog::query()->latest()->first())->not->toBeNull();
});

it('cria lembrete mensal quando a mensagem nao informa valor', function () {
    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Todo mes pagar academia dia 10',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    $job->handle(app(AIService::class), app(BaileysService::class), app(\App\Services\PhoneNumberService::class), app(\App\Services\PerformanceMetricsService::class));

    assertDatabaseHas('reminders', [
        'user_id' => $this->user->id,
        'title' => 'Pagar Academia',
        'frequency' => 'monthly',
        'day_of_month' => 10,
    ]);
});

it('prioriza lembrete mesmo com contexto anterior de orcamento', function () {
    Http::preventStrayRequests();

    $this->contact->update([
        'conversation_state' => [
            'mode' => 'idle',
            'last_action' => 'query_budgets',
            'last_entities' => [
                'topic' => 'budget',
                'category_name' => 'Alimentacao',
                'period' => 'monthly',
                'year' => now()->year,
                'month' => now()->month,
            ],
        ],
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Todo mes pagar academia dia 10',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    $job->handle(app(AIService::class), app(BaileysService::class), app(\App\Services\PhoneNumberService::class), app(\App\Services\PerformanceMetricsService::class));

    assertDatabaseHas('reminders', [
        'user_id' => $this->user->id,
        'title' => 'Pagar Academia',
        'frequency' => 'monthly',
        'day_of_month' => 10,
    ]);
});

it('tolera texto degradado em recorrencia mensal', function () {
    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Todo ms pagar academia 100 reais dia 10',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    $job->handle(app(AIService::class), app(BaileysService::class), app(\App\Services\PhoneNumberService::class), app(\App\Services\PerformanceMetricsService::class));

    assertDatabaseHas('recurring_transactions', [
        'user_id' => $this->user->id,
        'amount' => 100.00,
        'description' => 'Academia',
        'day_of_month' => 10,
    ]);
});

it('trata frase acentuada de recorrencia antes de cair na IA', function () {
    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Todo mês pagar academia dia 10',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    $job->handle(app(AIService::class), app(BaileysService::class), app(\App\Services\PhoneNumberService::class), app(\App\Services\PerformanceMetricsService::class));

    assertDatabaseHas('reminders', [
        'user_id' => $this->user->id,
        'title' => 'Pagar Academia',
        'frequency' => 'monthly',
        'day_of_month' => 10,
    ]);
});

it('pede a data quando o lembrete nao informa quando deve acontecer', function () {
    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->twice()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $first = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'me lembra de dar parabens para Maria',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    $first->handle(app(AIService::class), app(BaileysService::class), app(\App\Services\PhoneNumberService::class), app(\App\Services\PerformanceMetricsService::class));

    $this->contact->refresh();
    expect($this->contact->conversation_state['pending_intent'] ?? null)->toBe('create_reminder_schedule');

    $second = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'dia 10/06',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    $second->handle(app(AIService::class), app(BaileysService::class), app(\App\Services\PhoneNumberService::class), app(\App\Services\PerformanceMetricsService::class));

    assertDatabaseHas('reminders', [
        'user_id' => $this->user->id,
        'title' => 'Dar Parabens Para Maria',
        'frequency' => 'yearly',
        'day_of_month' => 10,
        'month_of_year' => 6,
    ]);
});
