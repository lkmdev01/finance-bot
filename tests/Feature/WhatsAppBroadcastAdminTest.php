<?php

use App\Models\User;
use App\Models\WhatsAppBroadcast;
use App\Models\WhatsAppContact;
use Illuminate\Support\Facades\Http;

it('forbids whatsapp broadcasts for non admin users', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'whatsapp_verified_at' => now(),
    ]);

    $this->actingAs($user);

    $this->get(route('admin.whatsapp-broadcasts.index'))->assertForbidden();
});

it('renders whatsapp broadcast console for admin users', function () {
    $admin = User::factory()->admin()->create([
        'email_verified_at' => now(),
        'whatsapp_verified_at' => now(),
    ]);

    $this->actingAs($admin);

    $this->get(route('admin.whatsapp-broadcasts.index'))
        ->assertOk()
        ->assertSee('Disparos WhatsApp')
        ->assertSee('Enviar mensagem agora');
});

it('sends a whatsapp broadcast to verified contacts and records audit rows', function () {
    config()->set('whatsapp.baileys.base_url', 'https://baileys.test');
    config()->set('whatsapp.baileys.webhook_secret', 'secret-test');

    Http::fake([
        'https://baileys.test/send-message' => Http::response(['ok' => true], 200),
    ]);

    $admin = User::factory()->admin()->create([
        'email_verified_at' => now(),
        'whatsapp_verified_at' => now(),
    ]);
    $recipient = User::factory()->create([
        'email_verified_at' => now(),
        'whatsapp_verified_at' => now(),
        'phone_number' => '13991290256',
    ]);

    WhatsAppContact::query()->create([
        'user_id' => $recipient->id,
        'phone_number' => '5513991290256',
        'name' => 'Lukas Martins',
        'context' => [],
    ]);

    $this->actingAs($admin);

    $response = $this->post(route('admin.whatsapp-broadcasts.store'), [
        'audience' => 'verified',
        'message' => 'Oi {{primeiro_nome}}, comunicado importante do InovaFinance. Email: {{email}}.',
        'confirm_compliance' => '1',
    ]);

    $response->assertRedirect(route('admin.whatsapp-broadcasts.index'));
    $response->assertSessionHas('message');

    expect(WhatsAppBroadcast::query()->count())->toBe(1);
    $broadcast = WhatsAppBroadcast::query()->first();
    $firstName = str($recipient->name)->squish()->explode(' ')->first();

    expect($broadcast->status)->toBe('sent')
        ->and($broadcast->admin_user_id)->toBe($admin->id)
        ->and($broadcast->user_id)->toBe($recipient->id)
        ->and($broadcast->phone_number)->toBe('5513991290256')
        ->and($broadcast->message)->toBe("Oi {$firstName}, comunicado importante do InovaFinance. Email: {$recipient->email}.");

    Http::assertSent(fn ($request) => $request->url() === 'https://baileys.test/send-message'
        && $request['phone'] === '5513991290256@s.whatsapp.net'
        && $request['secret'] === 'secret-test'
        && $request['message'] === "Oi {$firstName}, comunicado importante do InovaFinance. Email: {$recipient->email}.");
});

it('requires compliance confirmation before sending marketing messages', function () {
    $admin = User::factory()->admin()->create([
        'email_verified_at' => now(),
        'whatsapp_verified_at' => now(),
    ]);

    $this->actingAs($admin);

    $this->post(route('admin.whatsapp-broadcasts.store'), [
        'audience' => 'manual',
        'manual_phone' => '5513991290256',
        'message' => 'Mensagem sem confirmacao.',
    ])->assertSessionHasErrors('confirm_compliance');

    expect(WhatsAppBroadcast::query()->count())->toBe(0);
});
