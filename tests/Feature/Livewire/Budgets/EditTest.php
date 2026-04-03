<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use Livewire\Volt\Volt;

it('can render', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
    ]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
    ]);

    $component = Volt::test('budgets.edit', ['budget' => $budget]);

    $component->assertSee('');
});
