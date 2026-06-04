<?php

use App\Models\User;
use App\Services\WhatsAppActivationService;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\post;

it('shows the phone form for authenticated users without whatsapp verification', function () {
    $user = User::factory()->create([
        'phone_number' => null,
        'whatsapp_verified_at' => null,
    ]);

    actingAs($user)
        ->get(route('whatsapp.activation.show'))
        ->assertOk()
        ->assertSee('Conecte seu numero')
        ->assertSee('Gerar codigo de ativacao')
        ->assertSee('Abrir WhatsApp oficial');
});

it('stores the phone number and generates an activation code', function () {
    $user = User::factory()->create([
        'phone_number' => null,
        'whatsapp_verified_at' => null,
    ]);

    actingAs($user)
        ->post(route('whatsapp.activation.phone'), [
            'phone_number' => '(13) 97605-4715',
        ])
        ->assertRedirect(route('whatsapp.activation.show'));

    $user->refresh();

    expect($user->phone_number)->toBe('5513976054715');

    $activation = app(WhatsAppActivationService::class)->issueForUser($user, session()->getId());

    expect($activation->user_id)->toBe($user->id);
});

it('reconciles a legacy account when the phone already belongs to an unlinked user', function () {
    $legacyUser = User::factory()->create([
        'email' => 'legacy@example.com',
        'phone_number' => '5513976054715',
        'google_id' => null,
        'whatsapp_verified_at' => null,
    ]);

    $googleUser = User::factory()->create([
        'email' => 'google@example.com',
        'phone_number' => null,
        'auth_provider' => 'google',
        'google_id' => 'google-123',
        'google_avatar' => 'https://example.com/avatar.png',
        'whatsapp_verified_at' => null,
    ]);

    actingAs($googleUser)
        ->post(route('whatsapp.activation.phone'), [
            'phone_number' => '(13) 97605-4715',
        ])
        ->assertRedirect(route('whatsapp.activation.show'));

    $legacyUser->refresh();

    assertAuthenticatedAs($legacyUser);
    assertDatabaseMissing('users', ['id' => $googleUser->id]);

    expect($legacyUser->email)->toBe('google@example.com')
        ->and($legacyUser->google_id)->toBe('google-123')
        ->and($legacyUser->phone_number)->toBe('5513976054715');
});

it('completes activation after the whatsapp code was verified', function () {
    $user = User::factory()->create([
        'phone_number' => '5513976054715',
        'whatsapp_verified_at' => null,
    ]);

    actingAs($user);

    $activation = app(WhatsAppActivationService::class)->issueForUser($user, session()->getId());
    $activation->forceFill([
        'verified_phone_number' => $user->phone_number,
        'verified_at' => Carbon::now(),
    ])->save();

    post(route('whatsapp.activation.complete'))
        ->assertRedirect(route('dashboard'));

    expect($user->refresh()->whatsapp_verified_at)->not->toBeNull();
});
