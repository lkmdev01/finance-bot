<?php

use App\Models\User;

it('renders the public support page', function () {
    $this->get(route('support'))
        ->assertOk()
        ->assertSee('Suporte oficial')
        ->assertSee('Precisa de ajuda?')
        ->assertSee('WhatsApp ou IA');
});

it('forbids commercial readiness for non admin users', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'whatsapp_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('admin.commercial-readiness'))
        ->assertForbidden();
});

it('renders commercial readiness for admin users', function () {
    $admin = User::factory()->admin()->create([
        'email_verified_at' => now(),
        'whatsapp_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.commercial-readiness'))
        ->assertOk()
        ->assertSee('Pronto para vender?')
        ->assertSee('SMTP configurado')
        ->assertSee('Rotinas obrigatorias')
        ->assertSee('Canal claro para cliente');
});
