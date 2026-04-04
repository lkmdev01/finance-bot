<?php

use App\Models\User;
use Carbon\Carbon;

test('usuario com trial expirado ve paywall em relatorios', function () {
    $user = User::factory()->create([
        'trial_started_at' => now()->subDays(10),
        'trial_ends_at' => now()->subDays(3),
        'billing_plan_code' => null,
        'billing_plan_status' => null,
        'billing_access_ends_at' => null,
    ]);

    $this->actingAs($user)
        ->get(route('reports.index'))
        ->assertOk()
        ->assertSee('Relatórios avançados')
        ->assertSee('Ver planos');
});

test('usuario em teste gratis acessa recursos premium do pro', function () {
    $user = User::factory()->create([
        'trial_started_at' => now()->subDay(),
        'trial_ends_at' => now()->addDays(6),
        'billing_plan_code' => null,
        'billing_plan_status' => null,
        'billing_access_ends_at' => null,
    ]);

    expect($user->hasFeature('reports'))->toBeTrue()
        ->and($user->hasFeature('financial_projections'))->toBeTrue()
        ->and($user->hasFeature('mascot'))->toBeTrue();

    $this->actingAs($user)
        ->get(route('reports.index'))
        ->assertOk()
        ->assertDontSee('Recurso premium');

    $this->actingAs($user)
        ->get(route('financial-projections.index'))
        ->assertOk()
        ->assertDontSee('Recurso premium');

    $this->actingAs($user)
        ->get(route(config('mascot.route_name', 'mascot.index')))
        ->assertOk()
        ->assertDontSee('Recurso premium');
});

test('usuario com plano premium ativo acessa relatorios e projecoes', function () {
    Carbon::setTestNow('2026-04-01 12:00:00');

    $user = User::factory()->create([
        'billing_plan_code' => 'pro_monthly',
        'billing_plan_status' => 'active',
        'billing_access_ends_at' => now()->addMonth(),
    ]);

    $this->actingAs($user)
        ->get(route('reports.index'))
        ->assertOk()
        ->assertDontSee('Recurso premium');

    $this->actingAs($user)
        ->get(route('financial-projections.index'))
        ->assertOk()
        ->assertDontSee('Recurso premium');
});
