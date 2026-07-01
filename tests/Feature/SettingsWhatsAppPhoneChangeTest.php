<?php

use App\Models\User;
use App\Models\WhatsAppActivationCode;
use App\Models\WhatsAppContact;
use App\Services\WhatsAppActivationService;
use Livewire\Livewire;

it('generates a whatsapp change code without changing the current phone immediately', function () {
    $user = User::factory()->create([
        'phone_number' => '5513991111111',
        'whatsapp_verified_at' => now(),
    ]);

    $this->actingAs($user);

    Livewire::test('settings.whatsapp')
        ->set('phone_number', '(13) 98888-7777')
        ->call('startPhoneChange')
        ->assertHasNoErrors()
        ->assertSet('hasPendingChange', true);

    expect($user->fresh()->phone_number)->toBe('5513991111111')
        ->and(WhatsAppActivationCode::query()->where('user_id', $user->id)->whereNull('consumed_at')->exists())->toBeTrue();
});

it('confirms whatsapp phone change after code is verified from the new number', function () {
    $user = User::factory()->create([
        'phone_number' => '5513991111111',
        'whatsapp_verified_at' => now()->subDay(),
    ]);

    WhatsAppContact::query()->create([
        'user_id' => $user->id,
        'phone_number' => '5513991111111',
        'name' => 'Numero antigo',
    ]);

    $this->actingAs($user);

    $component = Livewire::test('settings.whatsapp')
        ->set('phone_number', '(13) 98888-7777')
        ->call('startPhoneChange')
        ->assertHasNoErrors();

    $activation = WhatsAppActivationCode::query()
        ->where('user_id', $user->id)
        ->whereNull('consumed_at')
        ->latest('id')
        ->firstOrFail();

    app(WhatsAppActivationService::class)->verifyCodeFromIncomingMessage($activation->code, '5513988887777');

    $component
        ->call('confirmPhoneChange')
        ->assertHasNoErrors()
        ->assertSet('hasPendingChange', false);

    $user->refresh();

    expect($user->phone_number)->toBe('5513988887777')
        ->and($user->whatsapp_verified_at)->not->toBeNull()
        ->and(WhatsAppContact::query()->where('phone_number', '5513988887777')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('does not allow changing whatsapp to a number owned by another user', function () {
    $user = User::factory()->create([
        'phone_number' => '5513991111111',
        'whatsapp_verified_at' => now(),
    ]);

    User::factory()->create([
        'phone_number' => '5513988887777',
        'whatsapp_verified_at' => now(),
    ]);

    $this->actingAs($user);

    Livewire::test('settings.whatsapp')
        ->set('phone_number', '(13) 98888-7777')
        ->call('startPhoneChange')
        ->assertHasErrors(['phone_number']);
});
