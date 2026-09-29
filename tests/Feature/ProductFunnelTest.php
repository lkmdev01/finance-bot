<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\ProductEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppConversationLog;
use App\Services\ProductEventService;

it('records product milestones only once per user', function () {
    $user = User::factory()->create();
    $events = app(ProductEventService::class);

    $events->recordWhatsAppActivity($user);
    $events->recordWhatsAppActivity($user);

    expect(ProductEvent::query()
        ->where('user_id', $user->id)
        ->where('event_name', ProductEventService::REGISTERED)
        ->count())->toBe(1)
        ->and(ProductEvent::query()
            ->where('user_id', $user->id)
            ->where('event_name', ProductEventService::FIRST_WHATSAPP_MESSAGE)
            ->count())->toBe(1);
});

it('captures the first transaction and budget milestones', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);

    Transaction::factory()->count(2)->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
    ]);

    Budget::factory()->count(2)->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
    ]);

    expect(ProductEvent::query()
        ->where('user_id', $user->id)
        ->where('event_name', ProductEventService::FIRST_TRANSACTION)
        ->count())->toBe(1)
        ->and(ProductEvent::query()
            ->where('user_id', $user->id)
            ->where('event_name', ProductEventService::FIRST_BUDGET)
            ->count())->toBe(1);
});

it('shows product funnel metrics on the beta dashboard', function () {
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
    $user = User::factory()->create();
    $events = app(ProductEventService::class);

    $events->recordOnce($user, ProductEventService::WHATSAPP_ACTIVATED, 'test');
    $events->recordOnce($user, ProductEventService::FIRST_WHATSAPP_MESSAGE, 'test');

    $this->actingAs($admin)
        ->get(route('admin.beta.index'))
        ->assertOk()
        ->assertSee('Funil do produto')
        ->assertSee('Primeira conversa')
        ->assertSee('Assinatura ativa');
});

it('backfills historical activation and retention milestones idempotently', function () {
    $user = User::factory()->create([
        'created_at' => now()->subDays(10),
        'whatsapp_verified_at' => now()->subDays(9),
    ]);

    WhatsAppConversationLog::query()->create([
        'user_id' => $user->id,
        'phone_number' => '5513999999999',
        'message' => 'saldo',
        'classification' => 'query_balance',
        'status' => 'handled',
        'reply' => 'Seu saldo',
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ]);

    $this->artisan('product:funnel-backfill')->assertSuccessful();
    $this->artisan('product:funnel-backfill')->assertSuccessful();

    expect(ProductEvent::query()
        ->where('user_id', $user->id)
        ->where('event_name', ProductEventService::RETAINED_D7)
        ->count())->toBe(1);
});
