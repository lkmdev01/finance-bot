<?php

use App\Models\User;
use Livewire\Volt\Volt;

it('can render', function () {
    $this->actingAs(User::factory()->create());

    $component = Volt::test('categories.create');

    $component->assertSee('');
});
