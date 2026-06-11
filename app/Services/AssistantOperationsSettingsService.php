<?php

namespace App\Services;

use App\Models\AssistantSetting;

class AssistantOperationsSettingsService
{
    public function current(): array
    {
        $setting = AssistantSetting::query()->first();

        return [
            'weekly_goal_review_runs' => (int) ($setting->weekly_goal_review_runs ?? config('assistant.weekly_goals.review_runs', 1)),
            'weekly_goal_item_approvals' => (int) ($setting->weekly_goal_item_approvals ?? config('assistant.weekly_goals.item_approvals', 10)),
            'weekly_goal_sync_runs' => (int) ($setting->weekly_goal_sync_runs ?? config('assistant.weekly_goals.sync_runs', 1)),
            'admin_whatsapp_number' => (string) ($setting->admin_whatsapp_number ?? config('assistant.admin_whatsapp_number', '')),
        ];
    }

    public function update(array $data): AssistantSetting
    {
        $setting = AssistantSetting::query()->first() ?? new AssistantSetting();
        $setting->fill([
            'weekly_goal_review_runs' => max(0, (int) ($data['weekly_goal_review_runs'] ?? 1)),
            'weekly_goal_item_approvals' => max(0, (int) ($data['weekly_goal_item_approvals'] ?? 10)),
            'weekly_goal_sync_runs' => max(0, (int) ($data['weekly_goal_sync_runs'] ?? 1)),
            'admin_whatsapp_number' => $data['admin_whatsapp_number'] ?: null,
        ]);
        $setting->save();

        return $setting;
    }
}
