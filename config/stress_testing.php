<?php

return [
    'default_users' => (int) env('STRESS_TEST_USERS', 100),
    'default_base_url' => env('STRESS_TEST_BASE_URL', 'http://127.0.0.1:8000'),
    'default_quantity' => (int) env('STRESS_TEST_QUANTITY', 1),
    'request_timeout_seconds' => (int) env('STRESS_TEST_TIMEOUT', 30),
    'crash_connection_error_threshold' => (float) env('STRESS_TEST_CRASH_THRESHOLD', 0.10),
    'report_json_path' => storage_path('app/stress_reports/latest.json'),
    'report_markdown_path' => storage_path('docs/STRESS_TEST_REPORT.md'),
];
