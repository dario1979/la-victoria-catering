<?php

return [
    'disk' => env('IMPORT_DISK', 'local'),
    'max_bytes' => (int) env('IMPORT_MAX_BYTES', 5 * 1024 * 1024),
    'max_rows' => (int) env('IMPORT_MAX_ROWS', 5000),
    'preview_rows' => 10,
    'retention_hours' => (int) env('IMPORT_RETENTION_HOURS', 72),
];
