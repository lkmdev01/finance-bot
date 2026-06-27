<?php

use App\Mail\AdminBroadcastMail;
use App\Models\User;

it('renders email broadcast page only for admins', function () {
    $admin = User::factory()->admin()->create(['whatsapp_verified_at' => now()]);
    $regular = User::factory()->create(['whatsapp_verified_at' => now()]);

    $this->actingAs($regular)
        ->get(route('admin.email-broadcasts.index'))
        ->assertForbidden();

    $this->actingAs($admin)
        ->get(route('admin.email-broadcasts.index'))
        ->assertOk()
        ->assertSee('Disparos de e-mail')
        ->assertSee('Variaveis disponiveis');
});

it('sends branded manual email broadcast and records log', function () {
    config()->set('mail.default', 'array');

    $admin = User::factory()->admin()->create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'whatsapp_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->post(route('admin.email-broadcasts.store'), [
            'audience' => 'manual',
            'manual_email' => 'cliente@example.com',
            'subject' => 'Novidade para {{primeiro_nome}}',
            'preheader' => 'Um resumo rapido',
            'headline' => 'Oi {{primeiro_nome}}, veja isso',
            'body' => "Temos uma novidade no InovaFinance.\nAcesse quando puder.",
            'cta_label' => 'Abrir painel',
            'cta_url' => route('dashboard'),
            'confirm_compliance' => '1',
        ])
        ->assertRedirect(route('admin.email-broadcasts.index'));

    $this->assertDatabaseHas('email_logs', [
        'to_email' => 'cliente@example.com',
        'subject' => 'Novidade para Contato',
        'notification_type' => AdminBroadcastMail::class,
        'status' => 'sent',
    ]);
});
