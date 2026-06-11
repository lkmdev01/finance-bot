<?php

use App\Assistant\Reports\AssistantObservabilityService;
use App\Services\AssistantOperationsSettingsService;
use App\Services\BaileysService;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    File::delete(storage_path('app/assistant/weekly_review_activity.jsonl'));
});

it('sends the weekly assistant summary to the configured admin whatsapp', function () {
    app(AssistantOperationsSettingsService::class)->update([
        'weekly_goal_review_runs' => 1,
        'weekly_goal_item_approvals' => 10,
        'weekly_goal_sync_runs' => 1,
        'admin_whatsapp_number' => '5511999991234',
    ]);

    $service = app(AssistantObservabilityService::class);
    $service->recordReviewRun([
        'days' => 7,
        'sample' => 100,
        'focus' => 'all',
        'sync' => true,
        'backlog_count' => 4,
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(function ($message) {
                return str_contains($message, 'Resumo semanal do assistente')
                    && str_contains($message, 'SLA:')
                    && str_contains($message, 'Abrir observabilidade:');
            }));
    });

    $this->artisan('assistant:send-weekly-summary')
        ->expectsOutputToContain('Resumo semanal enviado')
        ->assertSuccessful();
});
