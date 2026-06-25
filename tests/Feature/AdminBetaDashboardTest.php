<?php

use App\Models\AbacatePaySubscription;
use App\Models\User;
use App\Models\WhatsAppConversationLog;

it('forbids beta dashboard for non admin users', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'whatsapp_verified_at' => now(),
    ]);

    $this->actingAs($user);

    $this->get(route('admin.beta.index'))->assertForbidden();
});

it('renders beta dashboard for admin users with operational signals', function () {
    $admin = User::factory()->admin()->create([
        'email_verified_at' => now(),
        'whatsapp_verified_at' => now(),
    ]);

    $betaUser = User::factory()->create([
        'name' => 'Cliente Beta',
        'email' => 'cliente@example.com',
        'phone_number' => '5513999999999',
        'whatsapp_verified_at' => now(),
        'beta_status' => 'active',
        'billing_plan_code' => 'pro_monthly',
        'billing_plan_status' => 'active',
        'billing_access_ends_at' => now()->addMonth(),
    ]);

    WhatsAppConversationLog::query()->create([
        'user_id' => $betaUser->id,
        'phone_number' => '5513999999999',
        'message' => 'quais arquivos eu tenho no drive?',
        'classification' => 'query_drive_files',
        'status' => 'handled',
        'reply' => 'Seus arquivos recentes',
    ]);

    AbacatePaySubscription::query()->create([
        'user_id' => $betaUser->id,
        'plan_code' => 'pro_monthly',
        'status' => 'active',
        'frequency' => 'MONTHLY',
        'amount' => 2990,
        'currency' => 'BRL',
    ]);

    $this->actingAs($admin);

    $this->get(route('admin.beta.index'))
        ->assertOk()
        ->assertSee('Painel Beta')
        ->assertSee('Cliente Beta')
        ->assertSee('cliente@example.com')
        ->assertSee('WhatsApp ok')
        ->assertSee('Premium ativo')
        ->assertSee('quais arquivos eu tenho no drive?');
});

it('updates beta status and notes from dashboard', function () {
    $admin = User::factory()->admin()->create([
        'email_verified_at' => now(),
        'whatsapp_verified_at' => now(),
    ]);
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'whatsapp_verified_at' => now(),
    ]);

    $this->actingAs($admin);

    $this->patch(route('admin.beta.users.update', $user), [
        'beta_status' => 'invited',
        'beta_notes' => 'Entrou no beta para testar Drive e checkout.',
    ])->assertRedirect();

    $user->refresh();

    expect($user->beta_status)->toBe('invited')
        ->and($user->beta_notes)->toBe('Entrou no beta para testar Drive e checkout.')
        ->and($user->beta_invited_at)->not->toBeNull();
});
