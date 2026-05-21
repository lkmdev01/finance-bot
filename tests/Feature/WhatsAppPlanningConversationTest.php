<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Category;
use App\Models\FinancialProjection;
use App\Models\SavingsGoal;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\AIService;
use App\Services\BaileysService;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

function fakePlanningBaileysSuccessResponse(): Response
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

    $this->categoria = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'name' => 'Alimentacao',
    ]);
});

it('cria meta via whatsapp com frase natural', function () {
    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'Meta Viagem criada com valor de R$ 5.000,00');
            }))
            ->andReturn(fakePlanningBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Definir meta viagem 5 mil',
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

    expect(SavingsGoal::query()->where('user_id', $this->user->id)->where('name', 'Viagem')->exists())->toBeTrue();
});

it('cria meta via linguagem natural de poupanca', function () {
    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'Meta Viagem criada com valor de R$ 5.000,00');
            }))
            ->andReturn(fakePlanningBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Quero guardar 5 mil para viagem',
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

    expect(SavingsGoal::query()->where('user_id', $this->user->id)->where('name', 'Viagem')->exists())->toBeTrue();
});

it('edita meta via follow up com contexto recente', function () {
    SavingsGoal::create([
        'user_id' => $this->user->id,
        'name' => 'Viagem',
        'target_amount' => 5000,
        'is_completed' => false,
    ]);

    $this->contact->update([
        'conversation_state' => [
            'mode' => 'idle',
            'last_action' => 'query_savings',
            'last_entities' => ['topic' => 'savings', 'goal_name' => 'Viagem'],
            'recent_contexts' => [[
                'action' => 'query_savings',
                'entities' => ['topic' => 'savings', 'goal_name' => 'Viagem'],
                'reply_kind' => 'query',
                'timestamp' => now()->toIso8601String(),
            ]],
        ],
    ]);

    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'Meta Viagem atualizada');
            }))
            ->andReturn(fakePlanningBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'ajusta para 7000',
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

    expect((float) SavingsGoal::query()->where('user_id', $this->user->id)->where('name', 'Viagem')->first()->target_amount)->toBe(7000.0);
});

it('consulta metas com resposta contextual', function () {
    SavingsGoal::create([
        'user_id' => $this->user->id,
        'name' => 'Viagem',
        'target_amount' => 5000,
        'target_date' => now()->addMonths(4),
        'is_completed' => false,
    ]);

    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'Suas metas atuais')
                    && str_contains($message, 'Viagem')
                    && str_contains($message, 'progresso');
            }))
            ->andReturn(fakePlanningBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'quais sao minhas metas?',
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

it('consulta assinaturas com proximos vencimentos', function () {
    Subscription::create([
        'user_id' => $this->user->id,
        'category_id' => $this->categoria->id,
        'name' => 'Netflix',
        'amount' => 39.90,
        'billing_cycle' => 'monthly',
        'due_day' => now()->addDays(3)->day,
        'start_date' => now()->subMonths(2)->toDateString(),
        'next_due_date' => now()->addDays(3)->toDateString(),
        'auto_record' => false,
        'is_active' => true,
    ]);

    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'Suas assinaturas atuais')
                    && str_contains($message, 'Netflix')
                    && str_contains($message, 'vence em 3 dias');
            }))
            ->andReturn(fakePlanningBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'quais sao minhas assinaturas?',
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

it('cria assinatura via whatsapp com frase natural', function () {
    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'Assinatura Netflix criada com valor de R$ 19,00');
            }))
            ->andReturn(fakePlanningBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Criar assinatura Netflix mensal, dia 10. 19Reais',
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

    $subscription = Subscription::query()
        ->where('user_id', $this->user->id)
        ->where('name', 'Netflix')
        ->first();

    expect($subscription)->not->toBeNull();
    expect((float) $subscription->amount)->toBe(19.0);
    expect($subscription->billing_cycle)->toBe('monthly');
    expect((int) $subscription->due_day)->toBe(10);
});

it('edita assinatura via whatsapp com frase natural', function () {
    Subscription::create([
        'user_id' => $this->user->id,
        'category_id' => $this->categoria->id,
        'name' => 'Netflix',
        'amount' => 19.00,
        'billing_cycle' => 'monthly',
        'due_day' => 10,
        'start_date' => now()->toDateString(),
        'auto_record' => false,
        'is_active' => true,
    ]);

    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'Assinatura Netflix atualizada');
            }))
            ->andReturn(fakePlanningBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Ajustar assinatura Netflix para 25 reais dia 12',
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

    $subscription = Subscription::query()->where('user_id', $this->user->id)->where('name', 'Netflix')->first();

    expect((float) $subscription->amount)->toBe(25.0);
    expect((int) $subscription->due_day)->toBe(12);
});

it('cancela assinatura via follow up com contexto recente', function () {
    Subscription::create([
        'user_id' => $this->user->id,
        'category_id' => $this->categoria->id,
        'name' => 'Netflix',
        'amount' => 19.00,
        'billing_cycle' => 'monthly',
        'due_day' => 10,
        'start_date' => now()->toDateString(),
        'auto_record' => false,
        'is_active' => true,
    ]);

    $this->contact->update([
        'conversation_state' => [
            'mode' => 'idle',
            'last_action' => 'query_subscriptions',
            'last_entities' => ['topic' => 'subscriptions', 'subscription_name' => 'Netflix'],
            'recent_contexts' => [[
                'action' => 'query_subscriptions',
                'entities' => ['topic' => 'subscriptions', 'subscription_name' => 'Netflix'],
                'reply_kind' => 'query',
                'timestamp' => now()->toIso8601String(),
            ]],
        ],
    ]);

    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'Assinatura Netflix cancelada');
            }))
            ->andReturn(fakePlanningBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'cancela ela',
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

    expect(Subscription::query()->where('user_id', $this->user->id)->where('name', 'Netflix')->first()->is_active)->toBeFalse();
});

it('lista assinaturas canceladas e ativas sem cair no fallback da ia', function () {
    Subscription::create([
        'user_id' => $this->user->id,
        'category_id' => $this->categoria->id,
        'name' => 'Netflix',
        'amount' => 19.00,
        'billing_cycle' => 'monthly',
        'due_day' => 10,
        'start_date' => now()->toDateString(),
        'auto_record' => false,
        'is_active' => false,
    ]);

    $this->contact->update([
        'conversation_state' => [
            'mode' => 'idle',
            'last_action' => 'query_subscriptions',
            'last_entities' => ['topic' => 'subscriptions', 'subscription_name' => 'Netflix'],
            'recent_contexts' => [[
                'action' => 'query_subscriptions',
                'entities' => ['topic' => 'subscriptions', 'subscription_name' => 'Netflix'],
                'reply_kind' => 'query',
                'timestamp' => now()->toIso8601String(),
            ]],
        ],
    ]);

    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'Voce nao tem assinaturas ativas no momento.');
            }))
            ->andReturn(fakePlanningBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'lista as ativas',
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

it('consulta assinaturas canceladas pelo contexto recente', function () {
    Subscription::create([
        'user_id' => $this->user->id,
        'category_id' => $this->categoria->id,
        'name' => 'Netflix',
        'amount' => 19.00,
        'billing_cycle' => 'monthly',
        'due_day' => 10,
        'start_date' => now()->toDateString(),
        'auto_record' => false,
        'is_active' => false,
    ]);

    $this->contact->update([
        'conversation_state' => [
            'mode' => 'idle',
            'last_action' => 'query_subscriptions',
            'last_entities' => ['topic' => 'subscriptions'],
            'recent_contexts' => [[
                'action' => 'query_subscriptions',
                'entities' => ['topic' => 'subscriptions'],
                'reply_kind' => 'query',
                'timestamp' => now()->toIso8601String(),
            ]],
        ],
    ]);

    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'Suas assinaturas canceladas')
                    && str_contains($message, 'Netflix');
            }))
            ->andReturn(fakePlanningBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'cancelados',
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

it('consulta projecoes e consegue abrir um horizonte especifico', function () {
    FinancialProjection::create([
        'user_id' => $this->user->id,
        'projection_date' => now()->addMonth()->startOfMonth()->toDateString(),
        'projected_balance' => 1200,
        'projected_income' => 3000,
        'projected_expenses' => 1800,
        'assumptions' => [],
    ]);

    FinancialProjection::create([
        'user_id' => $this->user->id,
        'projection_date' => now()->addMonths(3)->startOfMonth()->toDateString(),
        'projected_balance' => -150,
        'projected_income' => 2500,
        'projected_expenses' => 2650,
        'assumptions' => [],
    ]);

    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'Para')
                    && str_contains($message, 'saldo de R$ -150,00')
                    && str_contains($message, 'saldo negativo');
            }))
            ->andReturn(fakePlanningBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'e daqui a 3 meses?',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    );

    $this->contact->update([
        'conversation_state' => [
            'mode' => 'idle',
            'last_action' => 'query_projections',
            'last_entities' => [
                'topic' => 'projections',
                'projection_month' => now()->addMonth()->format('Y-m'),
            ],
        ],
    ]);

    $job->handle(
        app(AIService::class),
        app(BaileysService::class),
        app(\App\Services\PhoneNumberService::class),
        app(\App\Services\PerformanceMetricsService::class)
    );
});
