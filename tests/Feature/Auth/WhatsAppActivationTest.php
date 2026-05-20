<?php

use App\Models\User;
use App\Services\WhatsAppActivationService;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

it('shows the phone form for authenticated users without whatsapp verification', function () {
    $user = User::factory()->create([
        'phone_number' => null,
        'whatsapp_verified_at' => null,
    ]);

    actingAs($user)
        ->get(route('whatsapp.activation.show'))
        ->assertOk()
        ->assertSee('Conecte seu número')
        ->assertSee('Gerar código de ativação');
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
