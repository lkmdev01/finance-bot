<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssistantSetting extends Model
{
    protected $fillable = [
        'weekly_goal_review_runs',
        'weekly_goal_item_approvals',
        'weekly_goal_sync_runs',
        'admin_whatsapp_number',
    ];

    protected function casts(): array
    {
        return [
            'weekly_goal_review_runs' => 'integer',
            'weekly_goal_item_approvals' => 'integer',
            'weekly_goal_sync_runs' => 'integer',
        ];
    }
}
