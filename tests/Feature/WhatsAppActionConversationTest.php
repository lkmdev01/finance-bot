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
