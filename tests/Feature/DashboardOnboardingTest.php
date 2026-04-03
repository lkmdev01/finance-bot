<?php

use App\Models\User;
use Livewire\Volt\Volt;

test('new users see the dashboard onboarding tutorial', function () {
    $user = User::factory()->create([
        'onboarding_tutorial_seen_at' => null,
    ]);

    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Bem-vindo ao seu cockpit financeiro');
});

test('users who already saw the onboarding do not see it again', function () {
    $user = User::factory()->create([
        'onboarding_tutorial_seen_at' => now(),
    ]);

    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Bem-vindo ao seu cockpit financeiro');
});

test('user can save the whatsapp number during onboarding', function () {
    $user = User::factory()->create([
        'onboarding_tutorial_seen_at' => null,
        'phone_number' => null,
    ]);

    $this->actingAs($user);

    Volt::test('dashboard')
        ->set('onboardingStep', 1)
        ->set('onboardingPhoneNumber', '(13) 99129-0256')
        ->call('saveOnboardingPhoneNumber')
        ->assertHasNoErrors()
        ->assertSet('onboardingStep', 2);

    expect($user->refresh()->phone_number)->toBe('5513991290256');
});

test('user can dismiss the onboarding tutorial', function () {
    $user = User::factory()->create([
        'onboarding_tutorial_seen_at' => null,
    ]);

    $this->actingAs($user);

    Volt::test('dashboard')
        ->call('dismissOnboardingTutorial')
        ->assertSet('showOnboardingTutorial', false);

    expect($user->refresh()->onboarding_tutorial_seen_at)->not->toBeNull();
});
