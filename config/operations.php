<?php

return [
    'backup' => [
        'last_restore_test_at' => env('BACKUP_LAST_RESTORE_TEST_AT'),
        'restore_test_max_age_days' => (int) env('BACKUP_RESTORE_TEST_MAX_AGE_DAYS', 90),
    ],
];
