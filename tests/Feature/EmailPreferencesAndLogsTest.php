<?php

use App\Models\EmailLog;
use App\Models\User;
use App\Notifications\BillingPlanExpiringNotification;
use App\Notifications\LoginAlertNotification;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

it('allows users to update email preferences', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('email-preferences.edit'))
        ->assertOk()
        ->assertSee('Preferencias de e-mail');

    Livewire::test('settings.email-preferences')
        ->set('preferences.billing', false)
        ->set('preferences.security', true)
        ->set('preferences.login_alerts', false)
        ->set('preferences.marketing', true)
        ->call('save')
        ->assertDispatched('email-preferences-updated');

    expect($user->fresh()->email_preferences)->toMatchArray([
        'billing' => false,
        'security' => true,
        'login_alerts' => false,
        'marketing' => true,
    ]);
});

it('sends login alert when preference is enabled', function () {
    Notification::fake();

    $user = User::factory()->create([
        'email_preferences' => ['login_alerts' => true],
    ]);

    event(new Login('web', $user, false));

    Notification::assertSentTo($user, LoginAlertNotification::class);
});

it('does not send login alert when preference is disabled', function () {
    Notification::fake();

    $user = User::factory()->create([
        'email_preferences' => ['login_alerts' => false],
    ]);

    event(new Login('web', $user, false));

    Notification::assertNotSentTo($user, LoginAlertNotification::class);
});

it('sends expiring plan emails and avoids recent duplicates', function () {
    Notification::fake();

    $user = User::factory()->create([
        'billing_plan_code' => 'pro_monthly',
        'billing_plan_status' => 'active',
        'billing_access_ends_at' => now()->addDays(2),
    ]);
    $duplicate = User::factory()->create([
        'billing_plan_code' => 'pro_monthly',
        'billing_plan_status' => 'active',
        'billing_access_ends_at' => now()->addDays(2),
    ]);

    EmailLog::query()->create([
        'user_id' => $duplicate->id,
        'to_email' => $duplicate->email,
        'subject' => 'Seu plano InovaFinance esta proximo de vencer',
        'notification_type' => BillingPlanExpiringNotification::class,
        'status' => 'sent',
    ]);

    $this->artisan('billing:send-expiring-emails')
        ->expectsOutput('Avisos enviados: 1')
        ->assertExitCode(0);

    Notification::assertSentTo($user, BillingPlanExpiringNotification::class);
    Notification::assertNotSentTo($duplicate, BillingPlanExpiringNotification::class);
});

it('renders admin email log page only for admins', function () {
    $admin = User::factory()->admin()->create(['whatsapp_verified_at' => now()]);
    $regular = User::factory()->create(['whatsapp_verified_at' => now()]);

    EmailLog::query()->create([
        'user_id' => $regular->id,
        'to_email' => $regular->email,
        'subject' => 'Teste InovaFinance',
        'notification_type' => BillingPlanExpiringNotification::class,
        'status' => 'sent',
    ]);

    $this->actingAs($regular)
        ->get(route('admin.email-logs.index'))
        ->assertForbidden();

    $this->actingAs($admin)
        ->get(route('admin.email-logs.index'))
        ->assertOk()
        ->assertSee('Historico de e-mails')
        ->assertSee('Teste InovaFinance');
});

it('records sent emails in email logs', function () {
    config()->set('mail.default', 'array');

    $user = User::factory()->create([
        'email' => 'historico@example.com',
    ]);

    Mail::raw('Mensagem de teste', function ($message) use ($user) {
        $message->to($user->email)->subject('Email registrado');
    });

    $this->assertDatabaseHas('email_logs', [
        'user_id' => $user->id,
        'to_email' => $user->email,
        'subject' => 'Email registrado',
        'status' => 'sent',
    ]);
});
