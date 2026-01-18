<?php

use Livewire\Volt\Volt;

it('can render', function () {
    $component = Volt::test('savings-goals.create');

    $component->assertSee('');
});
