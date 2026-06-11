<?php

use App\Services\AssistantOperationsSettingsService;
use App\Services\PhoneNumberService;
use App\Models\User;
use Livewire\Livewire;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    File::delete(storage_path('app/assistant/weekly_review_activity.jsonl'));
});

it('renders the assistant operations settings page for authenticated users', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('assistant.operations.settings'));

    $response->assertOk();
    $response->assertSee('Operação do Assistente', false);
    $response->assertSee('Meta de aprovações', false);
});

it('updates assistant operations settings through the livewire screen', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('settings.assistant-operations')
        ->set('weekly_goal_review_runs', 2)
        ->set('weekly_goal_item_approvals', 15)
        ->set('weekly_goal_sync_runs', 3)
        ->set('admin_whatsapp_number', '(11) 99999-1234')
        ->call('save')
        ->assertDispatched('assistant-operations-saved');

    $settings = app(AssistantOperationsSettingsService::class)->current();

    expect($settings['weekly_goal_review_runs'])->toBe(2)
        ->and($settings['weekly_goal_item_approvals'])->toBe(15)
        ->and($settings['weekly_goal_sync_runs'])->toBe(3)
        ->and($settings['admin_whatsapp_number'])->toBe(app(PhoneNumberService::class)->formatForStorage('(11) 99999-1234'));
});
