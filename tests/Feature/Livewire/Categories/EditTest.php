<?php

use App\Models\Category;
use App\Models\User;
use Livewire\Volt\Volt;

it('can render', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
        'name' => 'Mercado',
    ]);

    $component = Volt::test('categories.edit', ['category' => $category]);

    $component->assertSee('');
});
