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

function fakeBudgetBaileysSuccessResponse(): Response
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

    $this->categoriaBase = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'name' => 'Alimentação',
    ]);
});

it('entende follow up temporal de orçamento com base na categoria anterior', function () {
    $compras = Category::create([
        'user_id' => $this->user->id,
        'name' => 'Compras',
        'type' => 'expense',
        'color' => '#E67E22',
        'icon' => 'cart',
    ]);

    Budget::create([
        'user_id' => $this->user->id,
        'category_id' => $compras->id,
        'amount' => 500.00,
        'period' => 'monthly',
        'year' => now()->year,
        'month' => now()->month,
    ]);

    $pastDate = now()->copy()->subMonth();

    Budget::create([
        'user_id' => $this->user->id,
        'category_id' => $compras->id,
        'amount' => 350.00,
        'period' => 'monthly',
        'year' => $pastDate->year,
        'month' => $pastDate->month,
    ]);

    Http::preventStrayRequests();

    $this->contact->update([
        'conversation_state' => [
            'mode' => 'idle',
            'last_action' => 'query_budgets',
            'last_entities' => [
                'topic' => 'budget',
                'category_name' => 'Compras',
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
                    && str_contains($message, 'R$ 350,00')
                    && str_contains($message, $previousMonthLabel);
            }))
            ->andReturn(fakeBudgetBaileysSuccessResponse());
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

it('compara categorias de orçamento quando o usuário pergunta qual está mais apertada', function () {
    $compras = Category::create([
        'user_id' => $this->user->id,
        'name' => 'Compras',
        'type' => 'expense',
        'color' => '#E67E22',
        'icon' => 'cart',
    ]);

    Transaction::create([
        'user_id' => $this->user->id,
        'category_id' => $this->categoriaBase->id,
        'type' => 'expense',
        'amount' => 620.00,
        'description' => 'Mercado do mês',
        'date' => now()->toDateString(),
    ]);

    Transaction::create([
        'user_id' => $this->user->id,
        'category_id' => $compras->id,
        'type' => 'expense',
        'amount' => 120.00,
        'description' => 'Compra eventual',
        'date' => now()->toDateString(),
    ]);

    Budget::create([
        'user_id' => $this->user->id,
        'category_id' => $this->categoriaBase->id,
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
                return str_contains($message, 'mais apertada')
                    && str_contains($message, 'Alimentação')
                    && str_contains($message, 'Compras');
            }))
            ->andReturn(fakeBudgetBaileysSuccessResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'qual está mais apertada?',
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

it('traz sugestão útil depois de listar orçamentos', function () {
    $compras = Category::create([
        'user_id' => $this->user->id,
        'name' => 'Compras',
        'type' => 'expense',
        'color' => '#E67E22',
        'icon' => 'cart',
    ]);

    Budget::create([
        'user_id' => $this->user->id,
        'category_id' => $this->categoriaBase->id,
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

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'Seus orçamentos de')
                    && str_contains($message, 'comparar com o mês passado')
                    && str_contains($message, 'mais apertada');
            }))
            ->andReturn(fakeBudgetBaileysSuccessResponse());
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
