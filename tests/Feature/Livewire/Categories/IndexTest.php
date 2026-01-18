<?php

use Livewire\Volt\Volt;

it('can render', function () {
    $component = Volt::test('categories.index');

    $component->assertSee('');
});
