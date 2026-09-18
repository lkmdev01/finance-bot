<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Ai\FinancialAgent;
use App\Models\Category;
use App\Models\Note;
use App\Models\RecurringTransaction;
use App\Models\Reminder;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\AIService;
use App\Services\BaileysService;
use App\Services\PerformanceMetricsService;
use App\Services\PhoneNumberService;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;

function fakeAssistantHardeningBaileysResponse(): Response
{
    return new Response(new Psr7Response(200, [], json_encode(['success' => true])));
}

function runAssistantHardeningJob(ProcessWhatsAppMessage $job): void
{
    $job->handle(
        app(AIService::class),
        app(BaileysService::class),
        app(PhoneNumberService::class),
        app(PerformanceMetricsService::class),
    );
}

beforeEach(function () {
    config(['ai.use_sdk' => true]);
    $this->user = User::factory()->create([
        'phone_number' => '5513991290256',
    ]);

    $this->contact = WhatsAppContact::factory()->create([
        'user_id' => $this->user->id,
        'phone_number' => '5513991290256',
    ]);

    $this->expenseCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'expense',
        'name' => 'Assinaturas',
    ]);

    Ai::fakeAgent(FinancialAgent::class, function (string $prompt): array {
        $message = mb_strtolower(trim($prompt));
        if (str_contains($message, 'salvar nota')) {
            return ['action' => 'create_note', 'reply' => '', 'note_data' => ['title' => 'Revisar contrato', 'body' => 'revisar contrato com fornecedor']];
        }
        if (str_contains($message, 'me lembra')) {
            return ['action' => 'create_reminder', 'reply' => '', 'reminder_data' => ['title' => 'Pagar A Internet', 'message' => 'Pagar a internet', 'frequency' => 'once', 'next_trigger_at' => now()->addDay()->setTime(9, 0)->toDateTimeString()]];
        }
        if (str_contains($message, 'assino netflix')) {
            return ['action' => null, 'reply' => 'Qual o dia de vencimento?', 'conversation_metadata' => ['pending_intent' => 'create_subscription_details', 'clear_pending' => false]];
        }
        if ($message === 'dia 10') {
            return ['action' => 'create_subscription', 'reply' => '', 'subscription_data' => ['name' => 'Netflix', 'amount' => 39.90, 'billing_cycle' => 'monthly', 'due_day' => 10, 'start_date' => now()->toDateString()]];
        }
        if (str_contains($message, 'quais recorrencias')) {
            return ['action' => 'query_recurring_transactions', 'reply' => ''];
        }
        if (str_contains($message, 'cancela a recorrencia')) {
            return ['action' => 'cancel_recurring_transaction', 'reply' => '', 'recurring_data' => ['description' => 'Aluguel']];
        }
        if (str_contains($message, 'de aluguel')) {
            return ['action' => 'cancel_recurring_transaction', 'reply' => '', 'recurring_data' => ['description' => 'Aluguel']];
        }
        return ['action' => null, 'reply' => 'Ola!'];
    })->preventStrayPrompts();
});

it('routes note creation to notes instead of drive', function () {
    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeAssistantHardeningBaileysResponse());
    });

    runAssistantHardeningJob(new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'salvar nota: revisar contrato com fornecedor',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net',
    ));

    expect(Note::query()->where('user_id', $this->user->id)->exists())->toBeTrue();
    expect($this->user->fresh()->driveFiles()->count())->toBe(0);
});

it('routes reminder creation to reminders instead of drive', function () {
    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeAssistantHardeningBaileysResponse());
    });

    runAssistantHardeningJob(new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'me lembra de pagar a internet amanha as 9',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net',
    ));

    expect(Reminder::query()->where('user_id', $this->user->id)->exists())->toBeTrue();
    expect($this->user->fresh()->driveFiles()->count())->toBe(0);
});

it('treats assino netflix por valor mensal as subscription flow instead of reminder', function () {
    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->twice()->andReturn(fakeAssistantHardeningBaileysResponse());
    });

    runAssistantHardeningJob(new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'assino netflix por 39,90 todo mes',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net',
    ));

    expect(Reminder::query()->where('user_id', $this->user->id)->count())->toBe(0);

    runAssistantHardeningJob(new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'dia 10',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net',
    ));

    $subscription = Subscription::query()->where('user_id', $this->user->id)->where('name', 'Netflix')->first();
    expect($subscription)->not->toBeNull();
    expect((float) $subscription->amount)->toBe(39.90);
    expect((int) $subscription->due_day)->toBe(10);
});

it('lists recurring transactions through whatsapp query flow', function () {
    RecurringTransaction::create([
        'user_id' => $this->user->id,
        'category_id' => $this->expenseCategory->id,
        'type' => 'expense',
        'amount' => 1200.00,
        'description' => 'Aluguel',
        'frequency' => 'monthly',
        'start_date' => now()->toDateString(),
        'day_of_month' => 5,
        'is_active' => true,
    ]);

    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(fn (string $message) => str_contains($message, 'Suas recorrencias ativas') && str_contains($message, 'Aluguel')))
            ->andReturn(fakeAssistantHardeningBaileysResponse());
    });

    runAssistantHardeningJob(new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'quais recorrencias eu tenho?',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net',
    ));
});

it('supports recurring cancel flow with short target follow up', function () {
    RecurringTransaction::create([
        'user_id' => $this->user->id,
        'category_id' => $this->expenseCategory->id,
        'type' => 'expense',
        'amount' => 1200.00,
        'description' => 'Aluguel',
        'frequency' => 'monthly',
        'start_date' => now()->toDateString(),
        'day_of_month' => 5,
        'is_active' => true,
    ]);

    Http::preventStrayRequests();

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->twice()->andReturn(fakeAssistantHardeningBaileysResponse());
    });

    runAssistantHardeningJob(new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'cancela a recorrencia',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net',
    ));

    runAssistantHardeningJob(new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'de aluguel',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net',
    ));

    expect(RecurringTransaction::query()->where('user_id', $this->user->id)->where('description', 'Aluguel')->first()?->is_active)->toBeFalse();
});
