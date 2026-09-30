<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WhatsAppConversationLog;
use App\Services\Security\OutboundUrlGuard;
use Illuminate\Support\Facades\DB;

it('blocks regular users from global monitoring', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('monitoring.index'))
        ->assertForbidden();
});

it('allows admins to access global monitoring', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('monitoring.index'))
        ->assertOk();
});

it('blocks cross-user access to financial edit routes', function (string $route, string $modelClass) {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $attributes = ['user_id' => $owner->id];

    if ($modelClass === Transaction::class || $modelClass === Budget::class) {
        $attributes['category_id'] = Category::factory()->create(['user_id' => $owner->id])->id;
    }

    $record = $modelClass::factory()->create($attributes);

    $response = $this->actingAs($intruder)->get(route($route, $record));

    expect($response->status())->toBeIn([403, 404]);
})->with([
    ['transactions.edit', Transaction::class],
    ['categories.edit', Category::class],
    ['budgets.edit', Budget::class],
    ['webhooks.edit', Webhook::class],
]);

it('rejects private and local webhook destinations', function (string $url) {
    expect(fn () => app(OutboundUrlGuard::class)->assertAllowed($url))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'localhost' => 'https://localhost/hook',
    'loopback' => 'https://127.0.0.1/hook',
    'private network' => 'https://10.0.0.10/hook',
    'cloud metadata' => 'https://169.254.169.254/latest/meta-data',
    'non https' => 'http://8.8.8.8/hook',
]);

it('accepts a public https webhook destination', function () {
    expect(app(OutboundUrlGuard::class)->assertAllowed('https://8.8.8.8/hook'))->toBe('8.8.8.8');
});

it('encrypts sensitive conversation content and webhook secrets at rest', function () {
    $user = User::factory()->create();
    $log = WhatsAppConversationLog::query()->create([
        'user_id' => $user->id,
        'phone_number' => '5511999999999',
        'message' => 'comprei remédio por 42 reais',
        'reply' => 'Despesa registrada',
        'metadata' => ['intent' => 'create_transaction'],
    ]);
    $webhook = Webhook::factory()->create([
        'user_id' => $user->id,
        'secret' => 'segredo-super-secreto',
    ]);

    expect(DB::table('whats_app_conversation_logs')->where('id', $log->id)->value('message'))
        ->not->toContain('remédio')
        ->and($log->fresh()->message)->toBe('comprei remédio por 42 reais')
        ->and(DB::table('webhooks')->where('id', $webhook->id)->value('secret'))
        ->not->toContain('segredo-super-secreto')
        ->and($webhook->fresh()->secret)->toBe('segredo-super-secreto');
});

it('prunes conversation logs beyond the configured retention period', function () {
    $user = User::factory()->create();
    $old = WhatsAppConversationLog::query()->create([
        'user_id' => $user->id,
        'message' => 'registro antigo',
        'status' => 'processed',
    ]);
    $recent = WhatsAppConversationLog::query()->create([
        'user_id' => $user->id,
        'message' => 'registro recente',
        'status' => 'processed',
    ]);

    DB::table('whats_app_conversation_logs')->where('id', $old->id)->update([
        'created_at' => now()->subDays(91),
        'updated_at' => now()->subDays(91),
    ]);
    DB::table('whats_app_conversation_logs')->where('id', $recent->id)->update([
        'created_at' => now()->subDays(5),
        'updated_at' => now()->subDays(5),
    ]);

    $this->artisan('privacy:prune-conversation-logs --days=90')->assertSuccessful();

    expect(WhatsAppConversationLog::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(WhatsAppConversationLog::query()->whereKey($recent->id)->exists())->toBeTrue();
});
