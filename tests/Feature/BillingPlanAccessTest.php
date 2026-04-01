<?php

use App\Models\User;
use Carbon\Carbon;

test('usuario sem plano premium ve paywall em relatorios', function () {
    $user = User::factory()->create([
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
