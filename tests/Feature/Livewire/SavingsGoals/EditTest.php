<?php

use App\Models\SavingsGoal;
use App\Models\User;
use Livewire\Volt\Volt;

it('can render', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $goal = SavingsGoal::factory()->create([
        'user_id' => $user->id,
    ]);

    $component = Volt::test('savings-goals.edit', ['goal' => $goal]);

    $component->assertSee('');
});
