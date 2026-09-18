<?php

/** @noinspection PhpUndefinedFieldInspection */
/** @noinspection PhpUndefinedMethodInspection */

use App\Jobs\ProcessWhatsAppMessage;
use App\Ai\FinancialAgent;
use App\Models\BankAccount;
use App\Models\Budget;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\RecurringTransaction;
use App\Models\Reminder;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversationLog;
use App\Services\BaileysService;
use App\Services\WhatsApp\Handlers\DeleteReminderHandler;
use Carbon\Carbon;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Pest\TestSuite;
use Tests\TestCase;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

function currentTestCase(): TestCase
{
    /** @var TestCase $test */
    $test = TestSuite::getInstance()->test;

    return $test;
}

function runWhatsAppJob(ProcessWhatsAppMessage $job): void
{
    app()->call([$job, 'handle']);
}

function fakeActionBaileysSuccessResponse(): Response
{
    return new Response(new Psr7Response(200, [], json_encode(['success' => true])));
}

beforeEach(function () {
    config(['ai.use_sdk' => true]);

    currentTestCase()->user = User::factory()->create([
        'phone_number' => '5513991290256',
    ]);

    currentTestCase()->contact = WhatsAppContact::factory()->create([
        'user_id' => currentTestCase()->user->id,
        'phone_number' => '5513991290256',
    ]);

    currentTestCase()->compras = Category::factory()->create([
        'user_id' => currentTestCase()->user->id,
        'type' => 'expense',
        'name' => 'Compras',
    ]);

    Ai::fakeAgent(FinancialAgent::class, function (string $prompt): array {
        $message = mb_strtolower(trim($prompt));

        if (str_contains($message, 'ajusta para 700')) {
            return ['action' => 'update_budget', 'reply' => '', 'budget_data' => ['category_name' => 'Compras', 'amount' => 700, 'period' => 'monthly', 'year' => now()->year, 'month' => now()->month]];
        }
        if (str_contains($message, 'cancela esse orcamento')) {
            return ['action' => 'delete_budget', 'reply' => '', 'budget_data' => ['category_name' => 'Compras']];
        }
        if ($message === 'sim') {
            $pendingState = currentTestCase()->contact->fresh()->conversation_state ?? [];
            $pendingIntent = $pendingState['pending_intent'] ?? null;

            if ($pendingIntent === 'delete_budget') {
                return ['action' => 'delete_budget', 'reply' => '', 'budget_data' => ['category_name' => 'Compras', 'confirmed' => true]];
            }

            if ($pendingIntent === 'delete_transaction') {
                $transactionId = $pendingState['pending_payload']['transaction_data']['transaction_id'] ?? null;

                return ['action' => 'delete_transaction', 'reply' => '', 'transaction_data' => ['transaction_id' => $transactionId, 'confirmed' => true]];
            }

            $budget = Budget::query()->where('user_id', currentTestCase()->user->id)->first();
            if ($budget !== null) {
                return ['action' => 'delete_budget', 'reply' => '', 'budget_data' => ['category_name' => 'Compras', 'period' => $budget->period, 'year' => $budget->year, 'month' => $budget->month, 'confirmed' => true]];
            }

            $transaction = Transaction::query()->where('user_id', currentTestCase()->user->id)->orderBy('id')->first();
            if ($transaction !== null) {
                if (Transaction::query()->where('user_id', currentTestCase()->user->id)->count() === 1) {
                    return ['action' => null, 'reply' => 'Confirmacao recebida.'];
                }

                return ['action' => 'delete_transaction', 'reply' => '', 'transaction_data' => ['transaction_id' => $transaction->id, 'confirmed' => true]];
            }
        }
        if ($message === 'ajusta para 28') {
            return ['action' => 'edit_transaction', 'reply' => '', 'transaction_data' => ['amount' => 28]];
        }
        if (str_contains($message, 'ajusta esse no cartao nubank')) {
            return ['action' => 'edit_transaction', 'reply' => '', 'transaction_data' => ['credit_card_name' => 'Nubank']];
        }
        if (str_contains($message, 'apaga o penultimo')) {
            $transaction = Transaction::query()->where('user_id', currentTestCase()->user->id)->orderBy('id')->first();

            return ['action' => 'delete_transaction', 'reply' => '', 'transaction_data' => ['transaction_id' => $transaction?->id, 'confirmed' => true]];
        }
        if (str_contains($message, 'apaga essa')) {
            return ['action' => 'delete_transaction', 'reply' => '', 'transaction_data' => []];
        }
        if (str_contains($message, 'corrige o de ontem')) {
            return ['action' => 'edit_transaction', 'reply' => '', 'transaction_data' => ['date' => now()->subDay()->toDateString()]];
        }
        if (str_contains($message, 'para 28')) {
            return ['action' => 'edit_transaction', 'reply' => '', 'transaction_data' => ['amount' => 28]];
        }
        if (str_contains($message, 'esse foi no debito')) {
            return ['action' => 'edit_transaction', 'reply' => '', 'transaction_data' => ['payment_method' => 'debit']];
        }
        if (str_contains($message, 'gastei 20 no uber e 35 no mercado')) {
            return ['action' => 'create_transaction', 'reply' => '', 'transaction_data' => ['type' => 'expense', 'amount' => 20, 'description' => 'Uber']];
        }
        if (str_contains($message, 'todo dia 5 pago academia 89')) {
            return ['action' => 'create_recurring_transaction', 'reply' => '', 'recurring_data' => ['type' => 'expense', 'amount' => 89, 'description' => 'Academia', 'frequency' => 'monthly', 'day_of_month' => 5]];
        }
        if (str_contains($message, 'todo mes pagar academia 100') || str_contains($message, 'todo ms pagar academia 100')) {
            return ['action' => 'create_recurring_transaction', 'reply' => '', 'recurring_data' => ['type' => 'expense', 'amount' => 100, 'description' => 'Academia', 'frequency' => 'monthly', 'day_of_month' => 10]];
        }
        if (str_contains($message, 'todo dia 5 pago academia')) {
            return ['action' => null, 'reply' => 'Qual valor devo usar?'];
        }
        if ($message === '89') {
            return ['action' => 'create_recurring_transaction', 'reply' => '', 'recurring_data' => ['type' => 'expense', 'amount' => 89, 'description' => 'Academia', 'frequency' => 'monthly', 'day_of_month' => 5]];
        }
        if (str_contains($message, 'ajusta para 99')) {
            return ['action' => 'update_recurring_transaction', 'reply' => '', 'recurring_data' => ['amount' => 99, 'description' => 'Academia']];
        }
        if ($message === 'cancela ela') {
            return ['action' => 'cancel_recurring_transaction', 'reply' => '', 'recurring_data' => ['description' => 'Academia']];
        }
        if (str_contains($message, 'comprei celular por 2000 em 10x')) {
            return ['action' => 'create_installment_transaction', 'reply' => '', 'installment_data' => ['description' => 'Celular', 'total_amount' => 2000, 'installment_count' => 10, 'per_installment_amount' => 200, 'date' => now()->toDateString()]];
        }
        if (str_contains($message, 'todo mes pagar academia dia 10') || str_contains($message, 'todo mês pagar academia dia 10')) {
            return ['action' => 'create_reminder', 'reply' => '', 'reminder_data' => ['title' => 'Pagar Academia', 'message' => 'Pagar Academia', 'frequency' => 'monthly', 'day_of_month' => 10, 'next_trigger_at' => now()->setDay(10)->toDateTimeString()]];
        }
        if (str_contains($message, 'me lembra de dar parabens para maria')) {
            return ['action' => null, 'reply' => 'Quando devo lembrar?', 'conversation_metadata' => ['pending_intent' => 'create_reminder_schedule', 'pending_mode' => 'awaiting_clarification', 'pending_payload' => ['reminder_data' => ['title' => 'Dar Parabens Para Maria', 'message' => 'Dar Parabens Para Maria']], 'clear_pending' => false]];
        }
        if ($message === 'dia 10/06') {
            return ['action' => 'create_reminder', 'reply' => '', 'reminder_data' => ['title' => 'Dar Parabens Para Maria', 'message' => 'Dar Parabens Para Maria', 'frequency' => 'yearly', 'day_of_month' => 10, 'month_of_year' => 6, 'next_trigger_at' => now()->setMonth(6)->setDay(10)->toDateTimeString()]];
        }
        if (str_contains($message, 'mes que vem dia 5 pagar o design')) {
            return ['action' => 'create_reminder', 'reply' => '', 'reminder_data' => ['title' => 'Pagar O Design', 'message' => 'Pagar O Design', 'frequency' => 'once', 'next_trigger_at' => now()->addMonth()->setDay(5)->toDateTimeString()]];
        }
        if (str_contains($message, 'dia 5 do mes que vem de pagar o programador')) {
            return ['action' => 'create_reminder', 'reply' => '', 'reminder_data' => ['title' => 'Pagar O Programador', 'message' => 'Pagar O Programador', 'frequency' => 'once', 'next_trigger_at' => now()->addMonth()->setDay(5)->toDateTimeString()]];
        }
        if (str_contains($message, 'parabens para paulo anualmente')) {
            return ['action' => 'create_reminder', 'reply' => '', 'reminder_data' => ['title' => 'Dar Parabens Para Paulo', 'message' => 'Dar Parabens Para Paulo', 'frequency' => 'yearly', 'day_of_month' => 17, 'month_of_year' => 8, 'next_trigger_at' => now()->setMonth(8)->setDay(17)->toDateTimeString()]];
        }
        if (str_contains($message, 'todo dia as 08:30 de beber agua')) {
            return ['action' => 'create_reminder', 'reply' => '', 'reminder_data' => ['title' => 'Beber Agua', 'message' => 'Beber Agua', 'frequency' => 'daily', 'trigger_time' => '08:30:00', 'next_trigger_at' => now()->toDateTimeString()]];
        }
        if (str_contains($message, 'toda segunda feira as 19h de treinar')) {
            return ['action' => 'create_reminder', 'reply' => '', 'reminder_data' => ['title' => 'Treinar', 'message' => 'Treinar', 'frequency' => 'weekly', 'day_of_week' => 1, 'trigger_time' => '19:00:00', 'next_trigger_at' => now()->toDateTimeString()]];
        }
        if (str_contains($message, 'apague o lembrete falar com')) {
            return ['action' => 'delete_reminder', 'reply' => '', 'reminder_data' => ['title' => 'Falar Com João']];
        }
        if (str_contains($message, 'apagar todos os lembretes') || str_contains($message, 'apagar tudo')) {
            return ['action' => 'delete_reminder', 'reply' => '', 'reminder_data' => ['delete_all' => true]];
        }
        if ($message === 'oi' || $message === 'bom dia') {
            return ['action' => null, 'reply' => 'Ola! Como posso ajudar?'];
        }
        if (str_contains($message, 'como voce pode me ajudar') || $message === 'ajuda') {
            return ['action' => null, 'reply' => 'Voce quer ajuda com qual parte? Comandos e funcoes. Suporte humano.'];
        }
        if ($message === 'comandos') {
            return ['action' => null, 'reply' => 'Posso te ajudar de varios jeitos: registrar gasto, consultar saldo e usar o Drive.'];
        }
        if ($message === 'suporte') {
            return ['action' => null, 'reply' => 'suporte humano. E-mail: suporte@example.com. Pagina de suporte.'];
        }
        if (str_contains($message, 'mande o link para o site')) {
            return ['action' => null, 'reply' => 'Dashboard: '.route('dashboard')];
        }
        if (str_contains($message, 'paguei 120 no cartao')) {
            return ['action' => 'create_transaction', 'reply' => '', 'transaction_data' => ['type' => 'expense', 'amount' => 120, 'description' => 'Pagamento', 'payment_method' => 'credit']];
        }
        if (str_contains($message, 'usar') && str_contains($message, 'pad')) {
            return ['action' => 'create_transaction', 'reply' => '', 'transaction_data' => ['type' => 'expense', 'amount' => 120, 'description' => 'Pagamento', 'payment_method' => 'credit', 'use_default_card' => true]];
        }
        if (str_contains($message, 'comprei lanche 20')) {
            return ['action' => 'create_transaction', 'reply' => '', 'transaction_data' => ['type' => 'expense', 'amount' => 20, 'description' => 'Lanche', 'credit_card_name' => 'Nubank', 'payment_method' => str_contains($message, 'debito') ? 'debit' : 'credit']];
        }
        if ($message === 'credito') {
            return ['action' => 'create_transaction', 'reply' => '', 'transaction_data' => ['type' => 'expense', 'amount' => 20, 'description' => 'Lanche', 'credit_card_name' => 'Nubank', 'payment_method' => 'credit']];
        }
        if ($message === 'gastei 50') {
            return ['action' => 'create_transaction', 'reply' => '', 'transaction_data' => ['type' => 'expense', 'amount' => 50, 'description' => 'Gasto', 'payment_method' => 'debit']];
        }
        if (str_contains($message, 'me lembra de')) {
            return ['action' => 'create_reminder', 'reply' => '', 'reminder_data' => ['title' => 'Lembrete', 'description' => trim($prompt), 'frequency' => 'once', 'schedule' => '2026-06-10 09:00:00']];
        }
        if (str_contains($message, 'apagar') && str_contains($message, 'lembrete')) {
            return ['action' => 'delete_reminder', 'reply' => '', 'reminder_data' => ['title' => 'Revisar Caixa']];
        }
        if (str_contains($message, 'editar lembrete')) {
            return ['action' => 'edit_reminder', 'reply' => '', 'reminder_data' => ['current_title' => 'Tomar Água', 'frequency' => 'once', 'trigger_time' => '15:00:00', 'next_trigger_at' => '2026-05-25 15:00:00']];
        }

        return ['action' => null, 'reply' => 'Ola! Como posso ajudar?'];
    })->preventStrayPrompts();
});

it('edita orcamento por contexto recente', function () {
    $budget = Budget::create([
        'user_id' => currentTestCase()->user->id,
        'category_id' => currentTestCase()->compras->id,
        'amount' => 500.00,
        'period' => 'monthly',
        'year' => now()->year,
        'month' => now()->month,
    ]);

    Http::preventStrayRequests();

    currentTestCase()->contact->update([
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

    currentTestCase()->mock(BaileysService::class, function ($mock) {
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
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($job);
    assertDatabaseHas('budgets', [
        'id' => $budget->id,
        'amount' => 700.00,
    ]);
});

it('pede confirmacao antes de cancelar orcamento e apaga ao confirmar', function () {
    $budget = Budget::create([
        'user_id' => currentTestCase()->user->id,
        'category_id' => currentTestCase()->compras->id,
        'amount' => 500.00,
        'period' => 'monthly',
        'year' => now()->year,
        'month' => now()->month,
    ]);

    Http::preventStrayRequests();

    currentTestCase()->contact->update([
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

    currentTestCase()->mock(BaileysService::class, function ($mock) {
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
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($firstJob);
    $confirmJob = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'sim',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($confirmJob);
    assertDatabaseMissing('budgets', [
        'id' => $budget->id,
    ]);
});

it('edita transacao por contexto recente', function () {
    $transaction = Transaction::create([
        'user_id' => currentTestCase()->user->id,
        'whatsapp_contact_id' => currentTestCase()->contact->id,
        'category_id' => currentTestCase()->compras->id,
        'type' => 'expense',
        'amount' => 20.00,
        'description' => 'Uber',
        'date' => now()->toDateString(),
    ]);

    Http::preventStrayRequests();

    currentTestCase()->contact->update([
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

    currentTestCase()->mock(BaileysService::class, function ($mock) {
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
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($job);
    assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'amount' => 28.00,
    ]);
});

it('muda a fonte de uma transacao para um cartao pelo whatsapp', function () {
    Http::preventStrayRequests();

    $cash = BankAccount::create([
        'user_id' => currentTestCase()->user->id,
        'name' => 'Caixa',
        'institution' => 'Dinheiro',
        'type' => 'cash',
        'opening_balance' => 100.00,
        'currency' => 'BRL',
        'color' => '#000000',
        'is_active' => true,
    ]);

    $card = CreditCard::create([
        'user_id' => currentTestCase()->user->id,
        'name' => 'Nubank',
        'issuer' => 'Nubank',
        'brand' => 'Visa',
        'last_four' => '1234',
        'credit_limit' => 5000.00,
        'opening_balance' => 0.00,
        'closing_day' => 5,
        'due_day' => 25,
        'is_active' => true,
    ]);

    $transaction = Transaction::create([
        'user_id' => currentTestCase()->user->id,
        'whatsapp_contact_id' => currentTestCase()->contact->id,
        'category_id' => currentTestCase()->compras->id,
        'bank_account_id' => $cash->id,
        'type' => 'expense',
        'amount' => 22.00,
        'description' => 'Uber',
        'date' => now()->toDateString(),
    ]);

    currentTestCase()->contact->update([
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

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::type('string'))
            ->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'ajusta esse no cartao Nubank',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($job);

    assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'credit_card_id' => $card->id,
        'bank_account_id' => null,
    ]);
});

it('pede confirmacao antes de apagar transacao contextual e remove ao confirmar', function () {
    $transaction = Transaction::create([
        'user_id' => currentTestCase()->user->id,
        'whatsapp_contact_id' => currentTestCase()->contact->id,
        'category_id' => currentTestCase()->compras->id,
        'type' => 'expense',
        'amount' => 20.00,
        'description' => 'Uber',
        'date' => now()->toDateString(),
    ]);

    Http::preventStrayRequests();

    currentTestCase()->contact->update([
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

    currentTestCase()->mock(BaileysService::class, function ($mock) {
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
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($firstJob);
    $confirmJob = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'sim',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($confirmJob);
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

    currentTestCase()->mock(BaileysService::class, function ($mock) {
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
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($job);
    expect(Transaction::query()->where('user_id', currentTestCase()->user->id)->count())->toBe(2);
    assertDatabaseHas('transactions', [
        'user_id' => currentTestCase()->user->id,
        'amount' => 20.00,
        'description' => 'Uber',
    ]);
    assertDatabaseHas('transactions', [
        'user_id' => currentTestCase()->user->id,
        'amount' => 35.00,
        'description' => 'mercado',
    ]);
});

it('apaga o penultimo gasto por referencia curta', function () {
    Http::preventStrayRequests();

    $first = Transaction::create([
        'user_id' => currentTestCase()->user->id,
        'whatsapp_contact_id' => currentTestCase()->contact->id,
        'category_id' => currentTestCase()->compras->id,
        'type' => 'expense',
        'amount' => 20.00,
        'description' => 'Uber',
        'date' => now()->subDay()->toDateString(),
    ]);

    $second = Transaction::create([
        'user_id' => currentTestCase()->user->id,
        'whatsapp_contact_id' => currentTestCase()->contact->id,
        'category_id' => currentTestCase()->compras->id,
        'type' => 'expense',
        'amount' => 35.00,
        'description' => 'Mercado',
        'date' => now()->toDateString(),
    ]);

    currentTestCase()->contact->update([
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

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->twice()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'apaga o penultimo',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($job);
    $confirm = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'sim',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($confirm);
    assertDatabaseMissing('transactions', ['id' => $first->id]);
    assertDatabaseHas('transactions', ['id' => $second->id]);
});

it('permite completar edicao contextual de ontem em duas mensagens', function () {
    Http::preventStrayRequests();

    $transaction = Transaction::create([
        'user_id' => currentTestCase()->user->id,
        'whatsapp_contact_id' => currentTestCase()->contact->id,
        'category_id' => currentTestCase()->compras->id,
        'type' => 'expense',
        'amount' => 22.00,
        'description' => 'Almoco',
        'date' => now()->subDay()->toDateString(),
    ]);

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->twice()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $first = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'corrige o de ontem',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($first);
    $second = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'para 28',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($second);
    assertDatabaseHas('transactions', ['id' => $transaction->id, 'amount' => 28.00]);
});

it('marca o ultimo gasto como debito por follow-up curto', function () {
    Http::preventStrayRequests();

    $transaction = Transaction::create([
        'user_id' => currentTestCase()->user->id,
        'whatsapp_contact_id' => currentTestCase()->contact->id,
        'category_id' => currentTestCase()->compras->id,
        'type' => 'expense',
        'amount' => 18.00,
        'description' => 'Padaria',
        'date' => now()->toDateString(),
        'metadata' => [],
    ]);

    currentTestCase()->contact->update([
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

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'esse foi no debito',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($job);
    expect(Transaction::find($transaction->id)?->metadata['payment_method'] ?? null)->toBe('debit');
});

it('cria recorrencia via whatsapp', function () {
    Http::preventStrayRequests();

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'todo dia 5 pago academia 89',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($job);
    assertDatabaseHas('recurring_transactions', [
        'user_id' => currentTestCase()->user->id,
        'amount' => 89.00,
        'description' => 'Academia',
        'frequency' => 'monthly',
        'day_of_month' => 5,
    ]);
});

it('cria recorrencia mensal com valor e dia sem confundir o dia com o valor', function () {
    Http::preventStrayRequests();

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Todo mes pagar academia 100 reais dia 10',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($job);
    assertDatabaseHas('recurring_transactions', [
        'user_id' => currentTestCase()->user->id,
        'amount' => 100.00,
        'description' => 'Academia',
        'frequency' => 'monthly',
        'day_of_month' => 10,
    ]);
});

it('completa criacao de recorrencia em duas mensagens quando falta o valor', function () {
    Http::preventStrayRequests();

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->twice()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $first = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'todo dia 5 pago academia',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($first);

    $second = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: '89',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($second);

    assertDatabaseHas('recurring_transactions', [
        'user_id' => currentTestCase()->user->id,
        'amount' => 89.00,
        'description' => 'Academia',
        'frequency' => 'monthly',
        'day_of_month' => 5,
    ]);
});

it('edita recorrencia por contexto recente', function () {
    Http::preventStrayRequests();

    $recurring = RecurringTransaction::create([
        'user_id' => currentTestCase()->user->id,
        'category_id' => currentTestCase()->compras->id,
        'type' => 'expense',
        'amount' => 89.00,
        'description' => 'Academia',
        'frequency' => 'monthly',
        'start_date' => now()->toDateString(),
        'is_active' => true,
        'day_of_month' => 5,
    ]);

    currentTestCase()->contact->update([
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

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'ajusta para 99',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($job);
    assertDatabaseHas('recurring_transactions', [
        'id' => $recurring->id,
        'amount' => 99.00,
    ]);
});

it('cancela recorrencia por contexto recente', function () {
    Http::preventStrayRequests();

    $recurring = RecurringTransaction::create([
        'user_id' => currentTestCase()->user->id,
        'category_id' => currentTestCase()->compras->id,
        'type' => 'expense',
        'amount' => 89.00,
        'description' => 'Academia',
        'frequency' => 'monthly',
        'start_date' => now()->toDateString(),
        'is_active' => true,
        'day_of_month' => 5,
    ]);

    currentTestCase()->contact->update([
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

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'cancela ela',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($job);
    assertDatabaseHas('recurring_transactions', [
        'id' => $recurring->id,
        'is_active' => false,
    ]);
});

it('cria parcelamento via whatsapp', function () {
    Http::preventStrayRequests();

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'comprei celular por 2000 em 10x',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($job);
    expect(Transaction::query()->where('user_id', currentTestCase()->user->id)->count())->toBe(10);
    assertDatabaseHas('transactions', [
        'user_id' => currentTestCase()->user->id,
        'amount' => 200.00,
        'description' => 'Celular (1/10)',
    ]);
});

it('registra log estruturado da conversa', function () {
    Http::preventStrayRequests();

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'oi',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($job);
    assertDatabaseHas('whats_app_conversation_logs', [
        'user_id' => currentTestCase()->user->id,
        'message' => 'oi',
        'classification' => null,
        'status' => 'fallback_reply',
    ]);

    expect(WhatsAppConversationLog::query()->latest()->first())->not->toBeNull();
});

it('nao deixa contexto de drive capturar saudacao e pedido de ajuda', function () {
    Http::preventStrayRequests();

    currentTestCase()->contact->update([
        'conversation_state' => [
            'mode' => 'idle',
            'last_action' => 'query_drive_files',
            'last_entities' => [
                'topic' => 'drive',
                'recent_drive_file_ids' => [1],
            ],
        ],
    ]);

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->twice()
            ->with(\Mockery::type('string'), \Mockery::type('string'))
            ->andReturn(fakeActionBaileysSuccessResponse());
    });

    $greeting = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Bom dia',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($greeting);

    $help = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Como voce pode me ajudar',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($help);

    $logs = WhatsAppConversationLog::query()
        ->where('user_id', currentTestCase()->user->id)
        ->latest('id')
        ->take(2)
        ->get()
        ->values();

    expect($logs[0]->reply)->toContain('Voce quer ajuda com qual parte')
        ->and($logs[0]->reply)->toContain('Comandos e funcoes')
        ->and($logs[0]->reply)->toContain('Suporte humano')
        ->and($logs[1]->reply)->toContain('Ola');

    currentTestCase()->contact->refresh();
    expect(currentTestCase()->contact->conversation_state['last_entities'] ?? [])->toBeArray();
});

it('guia ajuda para comandos ou suporte humano sem prender outras intencoes', function () {
    Http::preventStrayRequests();

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->times(4)
            ->with(\Mockery::type('string'), \Mockery::type('string'))
            ->andReturn(fakeActionBaileysSuccessResponse());
    });

    runWhatsAppJob(new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'ajuda',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    ));

    runWhatsAppJob(new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'comandos',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    ));

    runWhatsAppJob(new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'ajuda',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    ));

    runWhatsAppJob(new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'suporte',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    ));

    $logs = WhatsAppConversationLog::query()
        ->where('user_id', currentTestCase()->user->id)
        ->latest('id')
        ->take(4)
        ->get()
        ->reverse()
        ->values();

    expect($logs[0]->reply)->toContain('Voce quer ajuda com qual parte')
        ->and($logs[1]->reply)->toContain('Posso te ajudar de varios jeitos')
        ->and($logs[1]->reply)->toContain('Posso te ajudar de varios jeitos')
        ->and($logs[1]->reply)->toContain('registrar gasto')
        ->and($logs[2]->reply)->toContain('Voce quer ajuda')
        ->and($logs[3]->reply)->toContain('suporte humano')
        ->and($logs[3]->reply)->toContain('E-mail:')
        ->and($logs[3]->reply)->toContain('Pagina de suporte');

    currentTestCase()->contact->refresh();
    expect(currentTestCase()->contact->conversation_state['last_entities'] ?? [])->toBeArray();
});

it('responde pedido de link do site com dashboard sem cair no fallback', function () {
    Http::preventStrayRequests();

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'Dashboard:')
                    && str_contains($message, route('dashboard'))
                    && ! str_contains($message, 'nao entendi')
                    && ! str_contains($message, 'não entendi');
            }))
            ->andReturn(fakeActionBaileysSuccessResponse());
    });

    runWhatsAppJob(new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Me mande o link para o site',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    ));

    $log = WhatsAppConversationLog::query()
        ->where('user_id', currentTestCase()->user->id)
        ->latest('id')
        ->first();

    expect($log->reply)->toContain(route('dashboard'));
});

it('cria lembrete mensal quando a mensagem nao informa valor', function () {
    Http::preventStrayRequests();

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Todo mes pagar academia dia 10',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($job);
    assertDatabaseHas('reminders', [
        'user_id' => currentTestCase()->user->id,
        'title' => 'Pagar Academia',
        'frequency' => 'monthly',
        'day_of_month' => 10,
    ]);
});

it('prioriza lembrete mesmo com contexto anterior de orcamento', function () {
    Http::preventStrayRequests();

    currentTestCase()->contact->update([
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

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Todo mes pagar academia dia 10',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($job);
    assertDatabaseHas('reminders', [
        'user_id' => currentTestCase()->user->id,
        'title' => 'Pagar Academia',
        'frequency' => 'monthly',
        'day_of_month' => 10,
    ]);
});

it('tolera texto degradado em recorrencia mensal', function () {
    Http::preventStrayRequests();

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Todo ms pagar academia 100 reais dia 10',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($job);
    assertDatabaseHas('recurring_transactions', [
        'user_id' => currentTestCase()->user->id,
        'amount' => 100.00,
        'description' => 'Academia',
        'day_of_month' => 10,
    ]);
});

it('trata frase acentuada de recorrencia antes de cair na IA', function () {
    Http::preventStrayRequests();

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Todo mês pagar academia dia 10',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($job);
    assertDatabaseHas('reminders', [
        'user_id' => currentTestCase()->user->id,
        'title' => 'Pagar Academia',
        'frequency' => 'monthly',
        'day_of_month' => 10,
    ]);
});

it('pede a data quando o lembrete nao informa quando deve acontecer', function () {
    Http::preventStrayRequests();

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->twice()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $first = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'me lembra de dar parabens para Maria',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($first);
    currentTestCase()->contact->refresh();
    expect(currentTestCase()->contact->conversation_state['pending_intent'] ?? null)->toBe('create_reminder_schedule');

    $second = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'dia 10/06',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($second);
    assertDatabaseHas('reminders', [
        'user_id' => currentTestCase()->user->id,
        'title' => 'Dar Parabens Para Maria',
        'frequency' => 'yearly',
        'day_of_month' => 10,
        'month_of_year' => 6,
    ]);
});

it('cria lembrete pontual para mes que vem com dia informado', function () {
    Http::preventStrayRequests();

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Me lembra de mes que vem dia 5 pagar o design?',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($job);
    assertDatabaseHas('reminders', [
        'user_id' => currentTestCase()->user->id,
        'title' => 'Pagar O Design',
        'frequency' => 'once',
        'day_of_month' => null,
        'month_of_year' => null,
    ]);
});

it('cria lembrete pontual para dia do mes que vem com preposicao', function () {
    Http::preventStrayRequests();

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Me lembra no dia 5 do mes que vem de pagar o programador',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($job);
    assertDatabaseHas('reminders', [
        'user_id' => currentTestCase()->user->id,
        'title' => 'Pagar O Programador',
        'frequency' => 'once',
        'day_of_month' => null,
        'month_of_year' => null,
    ]);
});

it('cria lembrete anual com dia e mes numerico', function () {
    Http::preventStrayRequests();

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'me lembra de dar parabens para Paulo anualmente dia 17 do mes 8?',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($job);
    assertDatabaseHas('reminders', [
        'user_id' => currentTestCase()->user->id,
        'title' => 'Dar Parabens Para Paulo',
        'frequency' => 'yearly',
        'day_of_month' => 17,
        'month_of_year' => 8,
    ]);
});

it('cria lembrete diario com horario', function () {
    Http::preventStrayRequests();

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'me lembra todo dia as 08:30 de beber agua',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($job);
    assertDatabaseHas('reminders', [
        'user_id' => currentTestCase()->user->id,
        'title' => 'Beber Agua',
        'frequency' => 'daily',
        'trigger_time' => '08:30:00',
    ]);
});

it('cria lembrete semanal com dia da semana e horario', function () {
    Http::preventStrayRequests();

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'me lembra toda segunda feira as 19h de treinar',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($job);
    assertDatabaseHas('reminders', [
        'user_id' => currentTestCase()->user->id,
        'title' => 'Treinar',
        'frequency' => 'weekly',
        'day_of_week' => 1,
        'trigger_time' => '19:00:00',
    ]);
});

it('apaga apenas o lembrete especificado pelo nome', function () {
    Http::preventStrayRequests();

    $reminderOne = Reminder::create([
        'user_id' => currentTestCase()->user->id,
        'title' => 'Falar Com João',
        'message' => 'Lembrete pontual: Falar Com João',
        'frequency' => 'once',
        'timezone' => config('app.timezone'),
        'next_trigger_at' => now()->addDay(),
        'trigger_time' => '09:00:00',
        'is_active' => true,
    ]);

    $reminderTwo = Reminder::create([
        'user_id' => currentTestCase()->user->id,
        'title' => 'Tomar Água',
        'message' => 'Lembrete diario: Tomar Água',
        'frequency' => 'daily',
        'timezone' => config('app.timezone'),
        'next_trigger_at' => now()->addDay(),
        'trigger_time' => '14:30:00',
        'is_active' => true,
    ]);

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'apague o lembrete falar com joão',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($job);

    assertDatabaseHas('reminders', [
        'id' => $reminderOne->id,
        'is_active' => false,
    ]);
    assertDatabaseHas('reminders', [
        'id' => $reminderTwo->id,
        'is_active' => true,
    ]);
});

it('apaga todos os lembretes sem confundir com titulo que comeca com todos', function () {
    Http::preventStrayRequests();

    $reminderOne = Reminder::create([
        'user_id' => currentTestCase()->user->id,
        'title' => 'Todos Os Dias De Fazer Reunião',
        'message' => 'Lembrete diario: Todos Os Dias De Fazer Reunião',
        'frequency' => 'daily',
        'timezone' => config('app.timezone'),
        'next_trigger_at' => now()->addDay(),
        'trigger_time' => '09:00:00',
        'is_active' => true,
    ]);

    $reminderTwo = Reminder::create([
        'user_id' => currentTestCase()->user->id,
        'title' => 'Completar O Saldo Da Caixa',
        'message' => 'Lembrete anual: Completar O Saldo Da Caixa',
        'frequency' => 'yearly',
        'timezone' => config('app.timezone'),
        'next_trigger_at' => now()->addDays(7),
        'trigger_time' => '09:00:00',
        'is_active' => true,
    ]);

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'apagar todos os lembretes',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($job);

    assertDatabaseHas('reminders', [
        'id' => $reminderOne->id,
        'is_active' => false,
    ]);
    assertDatabaseHas('reminders', [
        'id' => $reminderTwo->id,
        'is_active' => false,
    ]);
});

it('apaga todos os lembretes com capitalizacao variada', function () {
    Http::preventStrayRequests();

    $reminder = Reminder::create([
        'user_id' => currentTestCase()->user->id,
        'title' => 'Revisar Caixa',
        'message' => 'Lembrete diario: Revisar Caixa',
        'frequency' => 'daily',
        'timezone' => config('app.timezone'),
        'next_trigger_at' => now()->addDay(),
        'trigger_time' => '09:00:00',
        'is_active' => true,
    ]);

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Apagar Todos Os Lembretes',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($job);

    assertDatabaseHas('reminders', [
        'id' => $reminder->id,
        'is_active' => false,
    ]);
});

it('mantem compatibilidade com frase legada apagar tudo no handler', function () {
    Http::preventStrayRequests();

    $reminder = Reminder::create([
        'user_id' => currentTestCase()->user->id,
        'title' => 'Revisar Caixa',
        'message' => 'Lembrete diario: Revisar Caixa',
        'frequency' => 'daily',
        'timezone' => config('app.timezone'),
        'next_trigger_at' => now()->addDay(),
        'trigger_time' => '09:00:00',
        'is_active' => true,
    ]);

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'apagar tudo',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );

    $result = ['_resolved_message' => 'apagar tudo'];
    $handler = app(DeleteReminderHandler::class);

    $handled = $handler->handle('delete_reminder', $result, currentTestCase()->user, currentTestCase()->contact, $job);

    expect($handled)->toBeTrue();
    assertDatabaseHas('reminders', [
        'id' => $reminder->id,
        'is_active' => false,
    ]);
});

it('edita um lembrete pelo nome e atualiza horarios e frequencia', function () {
    Http::preventStrayRequests();

    $reminder = Reminder::create([
        'user_id' => currentTestCase()->user->id,
        'title' => 'Tomar Água',
        'message' => 'Lembrete diario: Tomar Água',
        'frequency' => 'daily',
        'timezone' => config('app.timezone'),
        'next_trigger_at' => now()->addDay(),
        'trigger_time' => '14:30:00',
        'is_active' => true,
    ]);

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'editar lembrete tomar água para 25/05/2026 as 15:00',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($job);

    $reminder->refresh();

    expect($reminder->title)->toBe('Tomar Água');
    expect($reminder->trigger_time)->toBe('15:00:00');
    expect($reminder->frequency)->toBe('once');
    expect($reminder->next_trigger_at?->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'))
        ->toBe(Carbon::parse('2026-05-25 15:00:00', config('app.timezone'))->format('Y-m-d H:i:s'));
});

it('solicita clarificacao de cartao e registra com cartao padrao', function () {
    Http::preventStrayRequests();

    $card = CreditCard::create([
        'user_id' => currentTestCase()->user->id,
        'name' => 'Nubank',
        'issuer' => 'Nubank',
        'brand' => 'Visa',
        'last_four' => '1234',
        'credit_limit' => 5000.00,
        'opening_balance' => 0.00,
        'closing_day' => 5,
        'due_day' => 25,
        'is_active' => true,
    ]);

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->twice()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $firstJob = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'paguei 120 no cartão',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($firstJob);

    $secondJob = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'usar cartão padrão',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($secondJob);

    assertDatabaseHas('transactions', [
        'user_id' => currentTestCase()->user->id,
        'amount' => 120.00,
        'credit_card_id' => $card->id,
        'bank_account_id' => null,
    ]);
});

it('assume credito quando informa cartao por nome sem especificar debito', function () {
    Http::preventStrayRequests();

    $card = CreditCard::create([
        'user_id' => currentTestCase()->user->id,
        'name' => 'Nubank',
        'issuer' => 'Nubank',
        'brand' => 'Visa',
        'last_four' => '1234',
        'credit_limit' => 5000.00,
        'opening_balance' => 0.00,
        'closing_day' => 5,
        'due_day' => 25,
        'is_active' => true,
    ]);

    BankAccount::create([
        'user_id' => currentTestCase()->user->id,
        'name' => 'Nubank',
        'institution' => 'Nubank',
        'type' => 'checking',
        'opening_balance' => 100.00,
        'currency' => 'BRL',
        'color' => '#000000',
        'is_active' => true,
    ]);

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->twice()
            ->with(\Mockery::type('string'), \Mockery::type('string'))
            ->andReturn(fakeActionBaileysSuccessResponse());
    });

    $firstJob = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'comprei lanche 20 no cartao Nubank',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($firstJob);

    $followUp = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'credito',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($followUp);

    assertDatabaseHas('transactions', [
        'user_id' => currentTestCase()->user->id,
        'amount' => 20.00,
        'credit_card_id' => $card->id,
        'bank_account_id' => null,
    ]);
});

it('registra no saldo quando informa debito junto do cartao por nome', function () {
    Http::preventStrayRequests();

    CreditCard::create([
        'user_id' => currentTestCase()->user->id,
        'name' => 'Nubank',
        'issuer' => 'Nubank',
        'brand' => 'Visa',
        'last_four' => '1234',
        'credit_limit' => 5000.00,
        'opening_balance' => 0.00,
        'closing_day' => 5,
        'due_day' => 25,
        'is_active' => true,
    ]);

    BankAccount::create([
        'user_id' => currentTestCase()->user->id,
        'name' => 'Nubank',
        'institution' => 'Nubank',
        'type' => 'checking',
        'opening_balance' => 100.00,
        'currency' => 'BRL',
        'color' => '#000000',
        'is_active' => true,
    ]);

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::type('string'))
            ->andReturn(fakeActionBaileysSuccessResponse());
    });

    $firstJob = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'comprei lanche 20 no cartao Nubank no debito',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($firstJob);

    assertDatabaseHas('transactions', [
        'user_id' => currentTestCase()->user->id,
        'amount' => 20.00,
        'credit_card_id' => null,
    ]);
});

it('usa conta caixa quando nao informa fonte em debito', function () {
    Http::preventStrayRequests();

    BankAccount::create([
        'user_id' => currentTestCase()->user->id,
        'name' => 'Caixa',
        'institution' => 'Dinheiro',
        'type' => 'cash',
        'opening_balance' => 100.00,
        'currency' => 'BRL',
        'color' => '#000000',
        'is_active' => true,
    ]);

    currentTestCase()->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeActionBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'gastei 50',
        userId: currentTestCase()->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );
    runWhatsAppJob($job);

    assertDatabaseHas('transactions', [
        'user_id' => currentTestCase()->user->id,
        'amount' => 50.00,
        'bank_account_id' => BankAccount::where('user_id', currentTestCase()->user->id)->where('type', 'cash')->first()->id,
    ]);
});
