<?php

return [
    'weekly_goals' => [
        'review_runs' => (int) env('ASSISTANT_WEEKLY_GOAL_REVIEW_RUNS', 1),
        'item_approvals' => (int) env('ASSISTANT_WEEKLY_GOAL_ITEM_APPROVALS', 10),
        'sync_runs' => (int) env('ASSISTANT_WEEKLY_GOAL_SYNC_RUNS', 1),
    ],
];
