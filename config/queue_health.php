<?php

return [
    'queue' => env('QUEUE_HEARTBEAT_QUEUE', env('QUEUE_NAME', env('REDIS_QUEUE', env('DB_QUEUE', 'default')))),
    'max_age_seconds' => (int) env('QUEUE_HEARTBEAT_MAX_AGE', 180),
    'alert_cooldown_minutes' => (int) env('OPERATIONAL_ALERT_COOLDOWN_MINUTES', 30),
];
