<?php

return [
    'worker_heartbeat_max_age_seconds' => (int) env('OPS_WORKER_HEARTBEAT_MAX_AGE', 180),
    'scheduler_heartbeat_max_age_seconds' => (int) env('OPS_SCHEDULER_HEARTBEAT_MAX_AGE', 180),
    'pending_notification_max_age_minutes' => (int) env('OPS_PENDING_NOTIFICATION_MAX_AGE', 15),
    'backup_manifest_directory' => env('OPS_BACKUP_MANIFEST_DIRECTORY'),
    'backup_max_age_hours' => (int) env('OPS_BACKUP_MAX_AGE_HOURS', 4),
];
