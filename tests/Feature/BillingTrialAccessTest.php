<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\AIService;
use App\Services\BaileysService;
use App\Services\PerformanceMetricsService;
use App\Services\PhoneNumberService;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

function fakeBillingTrialBaileysResponse(): Response
{
    return new Response(new Psr7Response(200, [], json_encode(['success' => true])));
}

test('new users receive a default free trial window', function () {
    $user = User::factory()->create();

    expect($user->trial_started_at)->not->toBeNull()
        ->and($user->trial_ends_at)->not->toBeNull()
        ->and($user->hasActiveTrial())->toBeTrue()
        ->and($user->hasWritableFinancialAccess())->toBeTrue();
});

test('expired trial users are redirected from create routes to billing', function () {
    $user = User::factory()->create([
        'trial_started_at' => now()->subDays(10),
        'trial_ends_at' => now()->subDays(3),
        'billing_plan_code' => null,
        'billing_plan_status' => null,
        'billing_access_ends_at' => null,
    ]);

    $this->actingAs($user)
        ->get(route('transactions.create'))
        ->assertRedirect(route('billing.plans'));
});

test('expired trial users cannot create transactions via api', function () {
    $user = User::factory()->create([
        'trial_started_at' => now()->subDays(10),
        'trial_ends_at' => now()->subDays(3),
        'billing_plan_code' => null,
        'billing_plan_status' => null,
        'billing_access_ends_at' => null,
    ]);

    $category = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 25.50,
            'description' => 'Teste bloqueado',
            'date' => now()->format('Y-m-d'),
            'category_id' => $category->id,
        ])
        ->assertStatus(403)
        ->assertJsonFragment([
            'message' => config('billing.trial_expired_message'),
        ]);

    expect(Transaction::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('expired trial users receive subscription message on whatsapp create intent', function () {
    $user = User::factory()->create([
        'phone_number' => '5513991290256',
        'trial_started_at' => now()->subDays(10),
        'trial_ends_at' => now()->subDays(3),
        'billing_plan_code' => null,
        'billing_plan_status' => null,
        'billing_access_ends_at' => null,
    ]);

    $contact = WhatsAppContact::factory()->create([
        'user_id' => $user->id,
        'phone_number' => '5513991290256',
    ]);

    $category = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
        'name' => 'Transporte',
    ]);

    Http::fake([
        'api.groq.com/*' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'reply' => '✅ Registrei sua despesa!',
                        'action' => 'create_transaction',
                        'transaction_data' => [
                            'type' => 'expense',
                            'amount' => 32.00,
                            'description' => 'Uber',
                            'category_id' => $category->id,
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
            ->with(\Mockery::type('string'), \Mockery::on(function (string $message) {
                return str_contains($message, 'teste gratuito terminou')
                    && str_contains($message, '/billing/plans');
            }))
            ->andReturn(fakeBillingTrialBaileysResponse());
    });

    $job = new ProcessWhatsAppMessage(
        phoneNumber: $contact->phone_number,
        message: 'gastei 32 no uber',
        userId: $user->id,
        pushName: 'Teste',
        remoteJid: '5513991290256@s.whatsapp.net',
    );

    app()->call([$job, 'handle']);

    expect(Transaction::query()->where('user_id', $user->id)->count())->toBe(0);
});
