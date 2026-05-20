<?php

use App\Models\User;

it('renders the regular dashboard for new users without showing onboarding tutorial copy', function () {
    $user = User::factory()->create([
        'onboarding_tutorial_seen_at' => null,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Saldo Livre')
        ->assertDontSee('Bem-vindo ao seu cockpit financeiro')
        ->assertDontSee('Configure uma vez.');
});

it('does not surface onboarding tutorial copy for users who already completed onboarding', function () {
    $user = User::factory()->create([
        'onboarding_tutorial_seen_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Bem-vindo ao seu cockpit financeiro')
        ->assertDontSee('Configure uma vez.');
});
